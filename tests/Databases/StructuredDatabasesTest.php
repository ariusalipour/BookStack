<?php

namespace Tests\Databases;

use BookStack\Databases\DatabaseService;
use BookStack\Databases\StructuredDatabase;
use BookStack\Databases\StructuredField;
use BookStack\Databases\StructuredRecord;
use BookStack\Entities\Models\Book;
use BookStack\Entities\Models\Page;
use BookStack\Entities\Tools\TrashCan;
use Illuminate\Support\Facades\DB;
use Tests\Api\TestsApi;
use Tests\TestCase;

class StructuredDatabasesTest extends TestCase
{
    use TestsApi;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('structured-databases.enabled', true);
    }

    public function test_disabled_feature_is_unavailable(): void
    {
        config()->set('structured-databases.enabled', false);
        $this->asAdmin()->get('/databases')->assertNotFound();
        $this->postJson('/databases', ['name' => 'Projects', 'book_id' => $this->entities->book()->id])->assertNotFound();
    }

    public function test_guest_is_required_to_sign_in(): void
    {
        $this->get('/databases')->assertRedirect('/login');
    }

    public function test_api_requires_authentication_and_returns_structured_data(): void
    {
        $this->getJson('/api/databases')->assertUnauthorized();
        $this->actingAsApiEditor();
        $book = $this->entities->book();
        $response = $this->postJson('/api/databases', ['name' => 'API projects', 'book_id' => $book->id]);
        $response->assertCreated()->assertJson(['name' => 'API projects']);
        $databaseId = $response->json('id');
        $this->getJson('/api/databases')->assertOk()->assertJson(['data' => [['id' => $databaseId]]]);
        $this->postJson("/api/databases/{$databaseId}/records", ['name' => 'API project', 'values' => []])
            ->assertCreated()->assertJson(['name' => 'API project']);
        $this->getJson("/api/databases/{$databaseId}")->assertOk()->assertJsonCount(1, 'records');
        config()->set('structured-databases.enabled', false);
        $this->getJson('/api/databases')->assertNotFound();
    }

    public function test_api_documentation_includes_database_routes_and_validation_metadata(): void
    {
        $this->actingAsApiEditor()->get('/api/docs')->assertOk()->assertSee('id="databases-store-field"', false);
        $response = $this->getJson('/api/docs.json')->assertOk();
        $endpoints = collect($response->json('databases'));
        $this->assertCount(8, $endpoints);
        $fieldEndpoint = $endpoints->firstWhere('name', 'databases-store-field');
        $this->assertSame('api/databases/{databaseId}/fields', $fieldEndpoint['uri']);
        $this->assertContains('required', $fieldEndpoint['body_params']['name']);
        $this->assertContains('in:text,number,checkbox,date,select,multi_select,url,email,relation,rollup', $fieldEndpoint['body_params']['type']);
        $this->assertContains('array', $fieldEndpoint['body_params']['config']);
        $updateEndpoint = $endpoints->firstWhere('name', 'databases-update-record');
        $this->assertContains('required', $updateEndpoint['body_params']['page_revision']);
        $this->assertContains('integer', $updateEndpoint['body_params']['version']);
    }

    public function test_database_creation_and_html_views(): void
    {
        $book = $this->entities->book();
        $response = $this->asEditor()->postJson('/databases', ['name' => 'Projects', 'book_id' => $book->id]);
        $response->assertSuccessful()->assertJson(['name' => 'Projects', 'book_id' => $book->id]);
        $id = $response->json('id');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
        $this->get('/databases')->assertOk()->assertSeeText('Projects');
        $this->get("/databases/{$id}")->assertOk()->assertSeeText('Projects');
    }

    public function test_html_writes_redirect_to_the_database(): void
    {
        $this->asEditor();
        $database = $this->database();
        $this->post("/databases/{$database->id}/records", ['name' => 'A project', 'values' => []])
            ->assertRedirect("/databases/{$database->id}");
    }

    public function test_viewer_can_read_but_cannot_change_schema_or_records(): void
    {
        $this->asAdmin();
        $database = $this->database();
        $record = $this->record($database, 'Visible project');
        $this->asViewer()->get("/databases/{$database->id}")->assertOk()->assertSeeText('Visible project');
        $this->postJson('/databases', ['name' => 'Forbidden', 'book_id' => $database->book_id])->assertForbidden();
        $this->postJson("/databases/{$database->id}/fields", [
            'name' => 'Status', 'type' => 'text', 'version' => $database->fresh()->version,
        ])->assertForbidden();
        $this->postJson("/databases/{$database->id}/records", ['name' => 'Forbidden', 'values' => []])->assertForbidden();
        $this->putJson("/databases/{$database->id}/records/{$record->id}", [
            'name' => 'Forbidden', 'values' => [], 'version' => $record->version,
            'page_revision' => $record->page->revision_count,
        ])->assertForbidden();
        $this->deleteJson("/databases/{$database->id}/records/{$record->id}", ['version' => $record->version])->assertForbidden();
    }

    public function test_record_is_a_real_bookstack_page_with_revisions_and_search(): void
    {
        $this->asEditor();
        $database = $this->database();
        $response = $this->postJson("/databases/{$database->id}/records", ['name' => 'Quartz project tracker', 'values' => []]);
        $response->assertSuccessful();
        $page = Page::query()->findOrFail($response->json('page_id'));
        $this->assertEquals($database->book_id, $page->book_id);
        $this->assertFalse((bool) $page->draft);
        $this->assertDatabaseHas('page_revisions', ['page_id' => $page->id, 'name' => 'Quartz project tracker']);
        $this->get($page->getUrl())->assertOk()->assertSeeText('Quartz project tracker');
        $this->get('/search?term=Quartz')->assertOk()->assertSeeText('Quartz project tracker');
        $this->putJson("/databases/{$database->id}/records/{$response->json('id')}", [
            'name' => 'Quartz revised tracker', 'values' => [], 'version' => $response->json('version'),
            'page_revision' => $response->json('page_revision'),
        ])->assertSuccessful();
        $this->assertEquals('Quartz revised tracker', $page->fresh()->name);
        $this->assertDatabaseHas('page_revisions', ['page_id' => $page->id, 'name' => 'Quartz revised tracker']);
    }

    public function test_record_updates_and_deletion_respect_individual_page_permissions(): void
    {
        $this->asAdmin();
        $database = $this->database();
        $record = $this->record($database, 'Read only project');
        $page = Page::query()->findOrFail($record->page_id);
        $editor = $this->users->editor();
        $this->permissions->setEntityPermissions($page, ['view'], $editor->roles->all());
        $this->asEditor()->get("/databases/{$database->id}")->assertOk()->assertSeeText('Read only project');
        $this->putJson("/databases/{$database->id}/records/{$record->id}", [
            'name' => 'Unauthorized change', 'values' => [], 'version' => $record->version,
            'page_revision' => $record->page->revision_count,
        ])->assertForbidden();
        $this->deleteJson("/databases/{$database->id}/records/{$record->id}", ['version' => $record->version])->assertForbidden();
        $this->assertEquals('Read only project', $page->fresh()->name);
    }

    public function test_rollup_configuration_rejects_foreign_relations_and_non_numeric_targets(): void
    {
        $this->asEditor();
        [$projects, $tasks, $relation] = $this->relatedDatabases();
        $text = $this->field($tasks, 'Description', 'text');
        $this->postJson("/databases/{$projects->id}/fields", [
            'name' => 'Invalid total', 'type' => 'rollup', 'version' => $projects->fresh()->version,
            'config' => ['relation_field_id' => $relation->id, 'target_field_id' => $text->id, 'operation' => 'sum'],
        ])->assertStatus(422);
        $this->postJson("/databases/{$tasks->id}/fields", [
            'name' => 'Foreign relation count', 'type' => 'rollup', 'version' => $tasks->fresh()->version,
            'config' => ['relation_field_id' => $relation->id, 'operation' => 'count'],
        ])->assertStatus(422);
    }

    public function test_typed_values_round_trip_without_decimal_precision_loss(): void
    {
        $this->asEditor();
        $database = $this->database();
        $text = $this->field($database, 'Notes', 'text');
        $number = $this->field($database, 'Budget', 'number');
        $checkbox = $this->field($database, 'Approved', 'checkbox');
        $date = $this->field($database, 'Due date', 'date');
        $select = $this->field($database, 'Status', 'select', ['options' => ['Planned', 'Active']]);
        $multi = $this->field($database, 'Topics', 'multi_select', ['options' => ['Design', 'Research']]);
        $url = $this->field($database, 'Website', 'url');
        $email = $this->field($database, 'Contact', 'email');
        $values = [
            $text->id => 'A useful note', $number->id => '9007199254740993.123456', $checkbox->id => true,
            $date->id => '2026-10-26', $select->id => 'Active', $multi->id => ['Design', 'Research'],
            $url->id => 'https://example.com/project', $email->id => 'project@example.com',
        ];
        $response = $this->postJson("/databases/{$database->id}/records", ['name' => 'Typed project', 'values' => $values]);
        $response->assertSuccessful()->assertJson(['values' => $values]);
        $this->get("/databases/{$database->id}/records/{$response->json('id')}/edit")->assertOk()->assertSeeText('Typed project');
    }

    public function test_invalid_values_are_rejected_without_creating_pages(): void
    {
        $this->asEditor();
        $database = $this->database();
        $number = $this->field($database, 'Amount', 'number');
        $date = $this->field($database, 'Date', 'date');
        $select = $this->field($database, 'Status', 'select', ['options' => ['Active']]);
        $url = $this->field($database, 'Website', 'url');
        $before = Page::query()->count();
        foreach (
            [
            [$number->id => 'not a number'], [$number->id => 0.1], [$date->id => '2026-02-30'],
            [$select->id => 'Unknown'], [$url->id => 'javascript:alert(1)'], ['unknown-field' => 'unexpected'],
            ] as $values
        ) {
            $this->postJson("/databases/{$database->id}/records", ['name' => 'Invalid project', 'values' => $values])->assertStatus(422);
        }
        $this->assertEquals($before, Page::query()->count());
    }

    public function test_relation_and_rollups_use_stable_record_ids(): void
    {
        $this->asEditor();
        [$projects, $tasks, $relation, $amount, $count, $sum] = $this->relatedDatabases();
        $first = $this->record($tasks, 'First task', [$amount->id => '0.1']);
        $second = $this->record($tasks, 'Second task', [$amount->id => '0.2']);
        $response = $this->postJson("/databases/{$projects->id}/records", [
            'name' => 'Linked project', 'values' => [$relation->id => [$first->id, $second->id]],
        ]);
        $response->assertSuccessful();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_column($response->json("values.{$relation->id}"), 'id'));
        $this->assertSame(2, $response->json("values.{$count->id}"));
        $this->assertSame('0.3', $response->json("values.{$sum->id}"));
        $this->assertNotEquals($first->page_id, $first->id);
    }

    public function test_relation_rejects_records_from_the_wrong_database_and_rollups_are_read_only(): void
    {
        $this->asEditor();
        [$projects, $tasks, $relation, $amount, $count] = $this->relatedDatabases();
        $wrong = $this->record($projects, 'Wrong database');
        $this->postJson("/databases/{$projects->id}/records", [
            'name' => 'Invalid relation', 'values' => [$relation->id => [$wrong->id]],
        ])->assertStatus(422);
        $this->postJson("/databases/{$projects->id}/records", [
            'name' => 'Invalid rollup', 'values' => [$count->id => 42],
        ])->assertStatus(422);
    }

    public function test_hidden_targets_do_not_leak_through_relations_rollups_or_exports(): void
    {
        $this->asAdmin();
        [$projects, $tasks, $relation, $amount, $count, $sum] = $this->relatedDatabases();
        $visible = $this->record($tasks, 'Visible task', [$amount->id => '10.25']);
        $secret = $this->record($tasks, 'Confidential task', [$amount->id => '999.75']);
        $record = $this->record($projects, 'Visible project', [$relation->id => [$visible->id, $secret->id]]);
        $this->permissions->setEntityPermissions(Page::query()->findOrFail($secret->page_id), []);
        $this->asViewer();
        $export = $this->export($projects);
        $json = json_encode($export, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret->id, $json);
        $this->assertStringNotContainsString('Confidential task', $json);
        $this->assertStringNotContainsString('999.75', $json);
        $row = collect($export['records'])->firstWhere('id', $record->id);
        $this->assertEquals([$visible->id], array_column($row['values'][$relation->id], 'id'));
        $this->assertSame(1, $row['values'][$count->id]);
        $this->assertSame('10.25', $row['values'][$sum->id]);
        $this->get("/databases/{$projects->id}")->assertOk()->assertDontSeeText('Confidential task');
        $this->asEditor()->postJson("/databases/{$projects->id}/records", [
            'name' => 'Probe private target', 'values' => [$relation->id => [$secret->id]],
        ])->assertStatus(422);
    }

    public function test_editing_visible_relation_values_preserves_hidden_links(): void
    {
        $this->asAdmin();
        [$projects, $tasks, $relation] = $this->relatedDatabases();
        $visible = $this->record($tasks, 'Visible task');
        $secret = $this->record($tasks, 'Confidential task');
        $record = $this->record($projects, 'Project', [$relation->id => [$visible->id, $secret->id]]);
        $this->permissions->setEntityPermissions(Page::query()->findOrFail($secret->page_id), []);
        $this->asEditor()->putJson("/databases/{$projects->id}/records/{$record->id}", [
            'name' => 'Updated project', 'values' => [$relation->id => []], 'version' => $record->version,
            'page_revision' => $record->page->revision_count,
        ])->assertSuccessful()->assertJsonPath("values.{$relation->id}", []);
        $this->asAdmin();
        $result = app(DatabaseService::class)->serializeRecord($record->fresh());
        $this->assertEquals([$secret->id], array_column($result['values'][$relation->id], 'id'));
    }

    public function test_batch_serialization_has_bounded_query_growth_and_rechecks_visibility(): void
    {
        $this->asAdmin();
        [$projects, $tasks, $relation, $amount, $count] = $this->relatedDatabases();
        $visible = $this->record($tasks, 'Visible task', [$amount->id => '1.25']);
        $secret = $this->record($tasks, 'Hidden target task', [$amount->id => '1000']);
        for ($index = 0; $index < 12; $index++) {
            $this->record($projects, 'Batch project ' . $index, [$relation->id => [$visible->id, $secret->id]]);
        }
        $service = app(DatabaseService::class);
        $loaded = $service->records($projects);
        $hiddenSource = $loaded->last();
        $this->permissions->setEntityPermissions(Page::query()->findOrFail($secret->page_id), []);
        $this->permissions->setEntityPermissions(Page::query()->findOrFail($hiddenSource->page_id), []);
        $this->asEditor();
        $small = $loaded->take(2);
        // Warm per-user permission caches before measuring row-count-dependent queries.
        $service->serializeRecords($projects, $small);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $smallResult = $service->serializeRecords($projects, $small);
            $smallQueryCount = count(DB::getQueryLog());
            DB::flushQueryLog();
            $largeResult = $service->serializeRecords($projects, $loaded);
            $largeQueryCount = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertCount(2, $smallResult);
        $this->assertCount(11, $largeResult);
        $this->assertLessThanOrEqual($smallQueryCount + 2, $largeQueryCount, 'Adding source rows should not add queries per row.');
        $this->assertNotContains($hiddenSource->id, $largeResult->pluck('id')->all());
        foreach ($largeResult as $row) {
            $this->assertEquals([$visible->id], array_column($row['values'][$relation->id], 'id'));
            $this->assertSame(1, $row['values'][$count->id]);
        }
    }

    public function test_private_target_database_is_redacted_and_its_links_survive_source_edits(): void
    {
        $this->asAdmin();
        [$projects, $tasks, $relation, $amount, $count, $sum] = $this->relatedDatabases();
        $secret = $this->record($tasks, 'Private task', [$amount->id => '99']);
        $source = $this->record($projects, 'Visible source', [$relation->id => [$secret->id]]);
        $this->permissions->setEntityPermissions(Book::query()->findOrFail($tasks->book_id), []);
        $this->asEditor();
        $export = $this->export($projects);
        $json = json_encode($export, JSON_THROW_ON_ERROR);
        foreach ([$tasks->id, $amount->id, $secret->id] as $privateId) {
            $this->assertStringNotContainsString($privateId, $json);
        }
        $definitions = collect($export['fields'])->keyBy('id');
        $this->assertNull($definitions[$relation->id]['config']['target_database_id']);
        $this->assertNull($definitions[$sum->id]['config']['target_field_id']);
        $row = $export['records'][0];
        $this->assertSame([], $row['values'][$relation->id]);
        $this->assertSame(0, $row['values'][$count->id]);
        $payload = [
            'name' => 'Updated visible source', 'values' => [$relation->id => []],
            'version' => $row['version'], 'page_revision' => $row['page_revision'],
        ];
        $updated = $this->putJson("/databases/{$projects->id}/records/{$source->id}", $payload)->assertSuccessful();
        $payload['version'] = $updated->json('version');
        $payload['page_revision'] = $updated->json('page_revision');
        $payload['values'][$relation->id] = [$secret->id];
        $this->putJson("/databases/{$projects->id}/records/{$source->id}", $payload)->assertNotFound();
        $this->asAdmin();
        $restored = app(DatabaseService::class)->serializeRecord($source->fresh());
        $this->assertEquals([$secret->id], array_column($restored['values'][$relation->id], 'id'));
        $this->assertSame('Updated visible source', $restored['name']);
    }

    public function test_hidden_source_records_and_database_are_not_exported(): void
    {
        $this->asAdmin();
        $database = $this->database();
        $secret = $this->record($database, 'Confidential source record');
        $this->permissions->setEntityPermissions(Page::query()->findOrFail($secret->page_id), []);
        $this->asViewer();
        $this->assertCount(0, $this->export($database)['records']);
        $this->get("/databases/{$database->id}/records/{$secret->id}/edit")->assertNotFound();
        $this->permissions->setEntityPermissions(Book::query()->findOrFail($database->book_id), []);
        $this->get('/databases')->assertOk()->assertDontSeeText($database->name);
        $this->get("/databases/{$database->id}")->assertNotFound();
        $this->getJson("/databases/{$database->id}/export")->assertNotFound();
    }

    public function test_stale_record_and_schema_versions_are_rejected(): void
    {
        $this->asEditor();
        $database = $this->database();
        $schemaVersion = $database->version;
        $field = $this->field($database, 'Note', 'text');
        $this->postJson("/databases/{$database->id}/fields", [
            'name' => 'Concurrent field', 'type' => 'text', 'version' => $schemaVersion,
        ])->assertStatus(409);
        $record = $this->record($database, 'Original', [$field->id => 'Initial']);
        $this->putJson("/databases/{$database->id}/records/{$record->id}", [
            'name' => 'First change', 'values' => [$field->id => 'First'], 'version' => $record->version,
            'page_revision' => $record->page->revision_count,
        ])->assertSuccessful();
        $this->putJson("/databases/{$database->id}/records/{$record->id}", [
            'name' => 'Lost change', 'values' => [$field->id => 'Second'], 'version' => $record->version,
            'page_revision' => $record->page->revision_count,
        ])->assertStatus(409);
        $this->deleteJson("/databases/{$database->id}/records/{$record->id}", ['version' => $record->version])->assertStatus(409);
        $this->assertEquals('First change', Page::query()->findOrFail($record->page_id)->name);
    }

    public function test_native_page_edits_invalidate_old_database_edit_tokens(): void
    {
        $this->asEditor();
        $database = $this->database();
        $field = $this->field($database, 'Note', 'text');
        foreach (['name', 'html'] as $changedProperty) {
            $record = $this->record($database, 'Original ' . $changedProperty, [$field->id => 'Before']);
            $original = app(DatabaseService::class)->serializeRecord($record);
            $nativeName = $changedProperty === 'name' ? 'Renamed in page editor' : $original['name'];
            $nativeBody = '<p>Native page editor content ' . $changedProperty . '</p>';
            $this->entities->updatePage($record->page, ['name' => $nativeName, 'html' => $nativeBody]);
            $payload = [
                'name' => $original['name'], 'values' => [$field->id => 'After'],
                'version' => $original['version'], 'page_revision' => $original['page_revision'],
            ];
            $this->putJson("/databases/{$database->id}/records/{$record->id}", $payload)->assertStatus(409);
            $this->assertEquals($nativeName, $record->page->fresh()->name);
            $this->assertSame('Before', $record->fresh()->values[$field->id]);
            $reloaded = app(DatabaseService::class)->serializeRecord($record->fresh());
            $payload['name'] = $reloaded['name'];
            $payload['page_revision'] = $reloaded['page_revision'];
            $this->putJson("/databases/{$database->id}/records/{$record->id}", $payload)
                ->assertSuccessful()->assertJsonPath('name', $nativeName)->assertJsonPath("values.{$field->id}", 'After');
            $this->assertStringContainsString('Native page editor content ' . $changedProperty, $record->page->fresh()->html);
        }
    }

    public function test_html_record_forms_normalize_checkbox_decimal_and_empty_multi_select(): void
    {
        $this->asEditor();
        $database = $this->database();
        $checkbox = $this->field($database, 'Approved', 'checkbox');
        $number = $this->field($database, 'Amount', 'number');
        $multi = $this->field($database, 'Topics', 'multi_select', ['options' => ['Research', 'Design']]);
        $date = $this->field($database, 'Due date', 'date');
        $select = $this->field($database, 'Status', 'select', ['options' => ['Active']]);
        $email = $this->field($database, 'Contact', 'email');
        $url = $this->field($database, 'Website', 'url');
        $this->post("/databases/{$database->id}/records", [
            'name' => 'HTML project',
            'values' => [
                $checkbox->id => '1', $number->id => '9007199254740993.123456', $multi->id => ['Design'],
                $date->id => '2026-10-26', $select->id => 'Active', $email->id => 'project@example.com', $url->id => 'https://example.com',
            ],
        ])->assertRedirect("/databases/{$database->id}");
        $record = $this->export($database)['records'][0];
        $this->assertTrue($record['values'][$checkbox->id]);
        $this->assertSame('9007199254740993.123456', $record['values'][$number->id]);
        $this->assertSame(['Design'], $record['values'][$multi->id]);
        $this->put("/databases/{$database->id}/records/{$record['id']}", [
            'name' => $record['name'], 'version' => $record['version'], 'page_revision' => $record['page_revision'],
            'values' => [
                $checkbox->id => '0', $number->id => '', $multi->id => '',
                $date->id => '', $select->id => '', $email->id => '', $url->id => '',
            ],
        ])->assertRedirect("/databases/{$database->id}");
        $updated = $this->export($database)['records'][0];
        $this->assertFalse($updated['values'][$checkbox->id]);
        $this->assertSame([], $updated['values'][$multi->id]);
        foreach ([$number, $date, $select, $email, $url] as $field) {
            $this->assertNull($updated['values'][$field->id]);
        }
    }

    public function test_permanent_page_and_book_deletion_purge_custom_records_relations_and_history(): void
    {
        $this->asAdmin();
        [$projects, $tasks, $relation, $amount] = $this->relatedDatabases();
        $task = $this->record($tasks, 'Task for deletion', [$amount->id => '25.75']);
        $project = $this->record($projects, 'Project for deletion', [$relation->id => [$task->id]]);
        $this->assertDatabaseHas('structured_record_changes', ['record_id' => $project->id]);
        $this->assertDatabaseHas('structured_relations', ['source_record_id' => $project->id, 'target_record_id' => $task->id]);
        $this->deleteJson("/databases/{$projects->id}/records/{$project->id}", ['version' => $project->version])->assertSuccessful();
        $page = Page::withTrashed()->findOrFail($project->page_id);
        app(TrashCan::class)->destroyFromDeletion($page->deletions()->firstOrFail());
        $this->assertDatabaseMissing('structured_records', ['id' => $project->id]);
        $this->assertDatabaseMissing('structured_relations', ['source_record_id' => $project->id]);
        $this->assertDatabaseMissing('structured_record_changes', ['record_id' => $project->id]);
        $this->assertDatabaseHas('structured_records', ['id' => $task->id]);

        $survivor = $this->record($projects, 'Surviving project', [$relation->id => [$task->id]]);
        $book = Book::query()->findOrFail($tasks->book_id);
        app(TrashCan::class)->softDestroyBook($book);
        app(TrashCan::class)->destroyFromDeletion($book->deletions()->firstOrFail());
        $this->assertDatabaseMissing('structured_databases', ['id' => $tasks->id]);
        $this->assertDatabaseMissing('structured_fields', ['database_id' => $tasks->id]);
        $this->assertDatabaseMissing('structured_records', ['database_id' => $tasks->id]);
        $this->assertDatabaseMissing('structured_record_changes', ['database_id' => $tasks->id]);
        $this->assertDatabaseMissing('structured_relations', ['target_record_id' => $task->id]);
        $this->assertDatabaseHas('structured_records', ['id' => $survivor->id]);
        $this->assertSame([], $this->export($projects)['records'][0]['values'][$relation->id]);
    }

    public function test_page_and_book_trash_hide_records_and_restore_preserves_them(): void
    {
        $this->asAdmin();
        $database = $this->database();
        $field = $this->field($database, 'Note', 'text');
        $record = $this->record($database, 'Restorable project', [$field->id => 'Keep this value']);
        $this->deleteJson("/databases/{$database->id}/records/{$record->id}", ['version' => $record->version])->assertSuccessful();
        $this->assertCount(0, $this->export($database)['records']);
        $page = Page::withTrashed()->findOrFail($record->page_id);
        $this->assertNotNull($page->deleted_at);
        app(TrashCan::class)->restoreFromDeletion($page->deletions()->firstOrFail());
        $export = $this->export($database);
        $this->assertCount(1, $export['records']);
        $this->assertSame('Keep this value', $export['records'][0]['values'][$field->id]);
        $book = Book::query()->findOrFail($database->book_id);
        app(TrashCan::class)->softDestroyBook($book);
        $this->get("/databases/{$database->id}")->assertNotFound();
        app(TrashCan::class)->restoreFromDeletion($book->deletions()->firstOrFail());
        $this->assertCount(1, $this->export($database)['records']);
    }

    public function test_export_keeps_database_field_and_record_identifiers(): void
    {
        $this->asEditor();
        $database = $this->database();
        $field = $this->field($database, 'Budget', 'number');
        $record = $this->record($database, 'Portable project', [$field->id => '24.95']);
        $export = $this->export($database);
        $this->assertSame('bookstack-structured-database', $export['format']);
        $this->assertSame(1, $export['version']);
        $this->assertSame($database->id, $export['database']['id']);
        $this->assertSame($field->id, $export['fields'][0]['id']);
        $this->assertSame('number', $export['fields'][0]['type']);
        $this->assertSame($record->id, $export['records'][0]['id']);
        $this->assertSame($record->page_id, $export['records'][0]['page_id']);
        $this->assertSame('24.95', $export['records'][0]['values'][$field->id]);
    }

    private function export(StructuredDatabase $database): array
    {
        $response = $this->getJson("/databases/{$database->id}/export");
        $response->assertOk()->assertDownload('database-' . $database->id . '.json');

        return json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function database(string $name = 'Test projects'): StructuredDatabase
    {
        return app(DatabaseService::class)->createDatabase(['name' => $name, 'book_id' => $this->entities->book()->id]);
    }

    private function field(StructuredDatabase $database, string $name, string $type, array $config = []): StructuredField
    {
        return app(DatabaseService::class)->addField($database, [
            'name' => $name, 'type' => $type, 'config' => $config, 'version' => $database->fresh()->version,
        ]);
    }

    private function record(StructuredDatabase $database, string $name, array $values = []): StructuredRecord
    {
        return app(DatabaseService::class)->createRecord($database, ['name' => $name, 'values' => $values]);
    }

    private function relatedDatabases(): array
    {
        $projects = $this->database('Projects');
        $tasks = $this->database('Tasks');
        $amount = $this->field($tasks, 'Amount', 'number');
        $relation = $this->field($projects, 'Tasks', 'relation', ['target_database_id' => $tasks->id]);
        $count = $this->field($projects, 'Task count', 'rollup', ['relation_field_id' => $relation->id, 'operation' => 'count']);
        $sum = $this->field($projects, 'Total', 'rollup', [
            'relation_field_id' => $relation->id, 'target_field_id' => $amount->id, 'operation' => 'sum',
        ]);

        return [$projects, $tasks, $relation, $amount, $count, $sum];
    }
}
