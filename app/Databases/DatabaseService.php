<?php

namespace BookStack\Databases;

use BookStack\Entities\Models\Book;
use BookStack\Entities\Models\Page;
use BookStack\Entities\Repos\PageRepo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** All custom data access goes through current BookStack book and page permissions. */
class DatabaseService
{
    public function __construct(protected PageRepo $pages)
    {
    }

    public function visibleDatabases(): Collection
    {
        return StructuredDatabase::query()->whereHas('book', fn (Builder $query) => $query->scopes('visible'))
            ->with('book')->orderBy('name')->get();
    }

    public function database(string $id): StructuredDatabase
    {
        return StructuredDatabase::query()->whereHas('book', fn (Builder $query) => $query->scopes('visible'))
            ->with('book')->findOrFail($id);
    }

    public function createDatabase(array $input): StructuredDatabase
    {
        $input = Validator::make($input, [
            'book_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
        ])->validate();
        $this->validateName($input['name']);

        return DB::transaction(function () use ($input) {
            $book = Book::visible()->lockForUpdate()->findOrFail($input['book_id']);
            abort_unless(userCan('book-update', $book), 403);
            if (StructuredDatabase::query()->where('book_id', $book->id)->exists()) {
                $this->invalid('book_id', 'This book already has a database.');
            }

            return StructuredDatabase::query()->create([
                'book_id' => $book->id,
                'name' => trim($input['name']),
                'version' => 1,
            ])->load('book');
        });
    }

    public function fields(StructuredDatabase $database): Collection
    {
        return $this->database($database->id)->fields()->get();
    }

    public function addField(StructuredDatabase $database, array $input): StructuredField
    {
        $input = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(StructuredField::TYPES)],
            'config' => ['sometimes', 'array'],
            'version' => ['required', 'integer', 'min:1'],
        ])->validate();
        $this->validateName($input['name']);

        return DB::transaction(function () use ($database, $input) {
            $database = $this->lockedDatabase($database);
            abort_unless(userCan('book-update', $database->book), 403);
            $this->checkVersion($database->version, (int) $input['version']);
            if ($database->fields()->count() >= 100) {
                $this->invalid('name', 'A database can have at most 100 fields.');
            }
            if ($database->fields()->where('name', trim($input['name']))->exists()) {
                $this->invalid('name', 'A field with this name already exists.');
            }
            $config = $this->validateConfig($database, $input['type'], $input['config'] ?? []);
            $field = $database->fields()->create([
                'name' => trim($input['name']),
                'type' => $input['type'],
                'config' => $config,
                'position' => $database->fields()->count(),
            ]);
            $database->increment('version');

            return $field;
        });
    }

    public function records(StructuredDatabase $database, string $search = ''): Collection
    {
        $database = $this->database($database->id);
        $query = $this->visibleRecordQuery($database)->with(['page.book', 'database']);
        if ($search !== '') {
            $query->whereHas('page', fn (Builder $query) => $query->where('name', 'like', '%' . addcslashes($search, '%_\\') . '%'));
        }

        return $query->orderBy('created_at')->orderBy('id')->get();
    }

    public function record(StructuredDatabase $database, string $id): StructuredRecord
    {
        $database = $this->database($database->id);

        return $this->visibleRecordQuery($database)->with(['page', 'database'])->findOrFail($id);
    }

    public function createRecord(StructuredDatabase $database, array $input): StructuredRecord
    {
        $input = $this->validateRecordInput($input, false);

        return DB::transaction(function () use ($database, $input) {
            $database = $this->lockedDatabase($database);
            abort_unless(userCan('page-create', $database->book), 403);
            [$values, $relations] = $this->normalizeValues($database, $input['values'] ?? []);
            $draft = $this->pages->getNewDraftPage($database->book);
            $page = $this->pages->publishDraft($draft, ['name' => trim($input['name'])]);
            $record = $database->records()->create(['page_id' => $page->id, 'values' => $values, 'version' => 1]);
            $this->saveRelations($record, $relations);
            $this->snapshot($record, 'create');

            return $record->load(['page', 'database']);
        });
    }

    public function updateRecord(StructuredDatabase $database, StructuredRecord $record, array $input): StructuredRecord
    {
        $input = $this->validateRecordInput($input, true);

        return DB::transaction(function () use ($database, $record, $input) {
            $database = $this->lockedDatabase($database);
            $record = $this->lockedRecord($database, $record);
            abort_unless(userCan('page-update', $record->page), 403);
            $this->checkVersion($record->version, (int) $input['version']);
            if (isset($input['name'])) {
                $this->checkVersion((int) $record->page->revision_count, (int) $input['page_revision']);
            }
            [$values, $relations] = $this->normalizeValues($database, $input['values'] ?? []);
            if (isset($input['name']) && trim($input['name']) !== $record->page->name) {
                $this->pages->update($record->page, ['name' => trim($input['name'])]);
            }
            $record->values = array_replace($record->values, $values);
            $record->version++;
            $record->save();
            $this->saveRelations($record, $relations);
            $this->snapshot($record, 'update');

            return $record->load(['page', 'database']);
        });
    }

    /** Retain custom state while the page is in the recycle bin so restoration is complete. */
    public function deleteRecord(StructuredDatabase $database, StructuredRecord $record, int $version): void
    {
        DB::transaction(function () use ($database, $record, $version) {
            $database = $this->lockedDatabase($database);
            $record = $this->lockedRecord($database, $record);
            abort_unless(userCan('page-delete', $record->page), 403);
            $this->checkVersion($record->version, $version);
            $record->version++;
            $record->save();
            $this->snapshot($record, 'delete');
            $this->pages->destroy($record->page);
        });
    }

    public function serializeRecord(StructuredRecord $record): array
    {
        $database = new StructuredDatabase(['id' => $record->database_id]);
        $serialized = $this->serializeRecords($database, collect([$record]))->first();
        abort_if($serialized === null, 404);

        return $serialized;
    }

    /**
     * Serialize a caller-selected set of records, rechecking every source and target.
     * Only IDs are trusted from the supplied records; all values and pages are fresh.
     * Query count depends on relation fields, rather than the number of source rows.
     *
     * @param Collection<int, StructuredRecord> $records
     * @return Collection<int, array>
     */
    public function serializeRecords(StructuredDatabase $database, Collection $records): Collection
    {
        $database = $this->database($database->id);
        $ids = $records->pluck('id')->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }
        $visible = $this->visibleRecordQuery($database)->whereIn('id', $ids)->with('page.book')->get()->keyBy('id');
        $fields = $database->fields;
        $relationTargets = $this->batchRelationTargets($fields, $visible->keys()->all());

        return $ids->map(fn (string $id) => $visible->get($id))
            ->filter()->map(fn (StructuredRecord $record) => $this->serializeVisibleRecord($record, $fields, $relationTargets))->values();
    }

    /** @param Collection<int, StructuredField> $fields */
    private function serializeVisibleRecord(StructuredRecord $record, Collection $fields, array $relationTargets): array
    {
        $values = [];
        foreach ($fields as $field) {
            if ($field->type === 'relation') {
                $targets = $relationTargets[$field->id][$record->id] ?? collect();
                $values[$field->id] = $targets->map(fn (StructuredRecord $target) => [
                    'id' => $target->id, 'name' => $target->page->name, 'url' => $target->page->getUrl(),
                ])->values()->all();
            } elseif ($field->type !== 'rollup') {
                $values[$field->id] = $record->values[$field->id] ?? null;
            }
        }
        foreach ($fields as $field) {
            if ($field->type !== 'rollup') {
                continue;
            }
            $targets = $relationTargets[$field->config['relation_field_id']][$record->id] ?? collect();
            $operation = $field->config['operation'];
            if ($operation === 'count') {
                $values[$field->id] = $targets->count();
            } else {
                $numbers = $targets->map(fn (StructuredRecord $target) => $target->values[$field->config['target_field_id']] ?? null)
                    ->filter(fn ($value) => $value !== null)->values()->all();
                $values[$field->id] = DecimalValue::aggregate($numbers, $operation);
            }
        }

        return [
            'id' => $record->id,
            'page_id' => $record->page_id,
            'name' => $record->page->name,
            'url' => $record->page->getUrl(),
            'version' => $record->version,
            'page_revision' => (int) $record->page->revision_count,
            'values' => $values,
        ];
    }

    /** Export exactly the data the current reader can see; never raw relation rows or audit snapshots. */
    public function export(StructuredDatabase $database): array
    {
        $database = $this->database($database->id);
        $visibleDatabaseIds = $this->visibleDatabases()->pluck('id')->all();
        $fields = $database->fields;

        return [
            'format' => 'bookstack-structured-database',
            'version' => 1,
            'database' => ['id' => $database->id, 'name' => $database->name, 'book_id' => $database->book_id, 'schema_version' => $database->version],
            'fields' => $fields->map(function (StructuredField $field) use ($fields, $visibleDatabaseIds) {
                $definition = $field->only(['id', 'name', 'type', 'config', 'position']);
                if ($field->type === 'relation' && !in_array($field->config['target_database_id'], $visibleDatabaseIds, true)) {
                    $definition['config']['target_database_id'] = null;
                } elseif ($field->type === 'rollup' && isset($field->config['target_field_id'])) {
                    $relation = $fields->firstWhere('id', $field->config['relation_field_id']);
                    if (!in_array($relation?->config['target_database_id'] ?? null, $visibleDatabaseIds, true)) {
                        $definition['config']['target_field_id'] = null;
                    }
                }

                return $definition;
            })->all(),
            'records' => $this->serializeRecords($database, $this->records($database))->all(),
        ];
    }

    /** @param Collection<int, StructuredField> $fields */
    private function batchRelationTargets(Collection $fields, array $sourceIds): array
    {
        $relationFields = $fields->where('type', 'relation');
        if ($relationFields->isEmpty() || !$sourceIds) {
            return [];
        }
        $targetDatabaseIds = $relationFields->map(fn (StructuredField $field) => $field->config['target_database_id'])->unique();
        $databases = StructuredDatabase::query()->whereIn('id', $targetDatabaseIds)
            ->whereHas('book', fn (Builder $query) => $query->scopes('visible'))->get()->keyBy('id');
        $linksByField = DB::table('structured_relations')->whereIn('source_record_id', $sourceIds)
            ->whereIn('field_id', $relationFields->pluck('id'))->orderBy('target_record_id')->get()->groupBy('field_id');
        $result = [];
        foreach ($relationFields as $field) {
            $database = $databases->get($field->config['target_database_id']);
            $links = $linksByField->get($field->id, collect());
            if (!$database || $links->isEmpty()) {
                continue;
            }
            $targets = $this->visibleRecordQuery($database)->whereIn('id', $links->pluck('target_record_id')->unique())
                ->with('page.book')->get()->keyBy('id');
            $result[$field->id] = $links->groupBy('source_record_id')->map(
                fn (Collection $sourceLinks) => $sourceLinks->map(fn (object $link) => $targets->get($link->target_record_id))->filter()->values()
            );
        }

        return $result;
    }

    /** @return Builder<StructuredRecord> */
    private function visibleRecordQuery(StructuredDatabase $database): Builder
    {
        return StructuredRecord::query()->where('database_id', $database->id)
            ->whereHas('page', fn (Builder $query) => $query->scopes('visible')->where('book_id', $database->book_id)->where('draft', false));
    }

    private function lockedDatabase(StructuredDatabase $database): StructuredDatabase
    {
        $this->database($database->id);

        return StructuredDatabase::query()->with('book')->lockForUpdate()->findOrFail($database->id);
    }

    private function lockedRecord(StructuredDatabase $database, StructuredRecord $record): StructuredRecord
    {
        // Custom writes always lock database, then record, then native page. The page
        // lock also serializes against normal BookStack page updates without hooks.
        $record = $this->visibleRecordQuery($database)->lockForUpdate()->findOrFail($record->id);
        $page = Page::visible()->where('book_id', $database->book_id)->where('draft', false)
            ->lockForUpdate()->findOrFail($record->page_id);
        $record->setRelation('page', $page);

        return $record;
    }

    private function visibleRelationTargets(StructuredRecord $record, StructuredField $field): Collection
    {
        $targetDatabase = $this->visibleDatabases()->firstWhere('id', $field->config['target_database_id']);
        if (!$targetDatabase) {
            return collect();
        }

        return $this->visibleRecordQuery($targetDatabase)->with('page')->whereIn('id', function ($query) use ($record, $field) {
            $query->select('target_record_id')->from('structured_relations')
                ->where('source_record_id', $record->id)->where('field_id', $field->id);
        })->orderBy('id')->get();
    }

    private function validateRecordInput(array $input, bool $updating): array
    {
        $validated = Validator::make($input, [
            'name' => [$updating ? 'sometimes' : 'required', 'required', 'string', 'max:255'],
            'values' => ['sometimes', 'array'],
            'version' => [$updating ? 'required' : 'sometimes', 'integer', 'min:1'],
            'page_revision' => [$updating ? Rule::requiredIf(array_key_exists('name', $input)) : 'sometimes', 'integer', 'min:1'],
        ])->validate();
        if (isset($validated['name'])) {
            $this->validateName($validated['name']);
        }

        return $validated;
    }

    private function validateConfig(StructuredDatabase $database, string $type, array $config): array
    {
        $rules = match ($type) {
            'select', 'multi_select' => ['options' => ['required', 'array', 'list', 'min:1', 'max:100'], 'options.*' => ['required', 'string', 'max:255', 'distinct:strict']],
            'relation' => ['target_database_id' => ['required', 'uuid']],
            'rollup' => [
                'relation_field_id' => ['required', 'uuid'],
                'target_field_id' => ['nullable', 'uuid'],
                'operation' => ['required', Rule::in(['count', 'sum', 'average', 'min', 'max'])],
            ],
            default => [],
        };
        foreach (array_keys($config) as $key) {
            if (!isset($rules[$key])) {
                $this->invalid('config', 'Unknown configuration property.');
            }
        }
        $prefixedRules = [];
        foreach ($rules as $key => $rule) {
            $prefixedRules['config.' . $key] = $rule;
        }
        $config = Validator::make(['config' => $config], $prefixedRules)->validate()['config'] ?? [];
        if ($type === 'select' || $type === 'multi_select') {
            foreach ($config['options'] as $option) {
                if (trim($option) === '') {
                    $this->invalid('config.options', 'Options must not be blank.');
                }
            }
        }
        if ($type === 'relation') {
            $this->database($config['target_database_id']);
        }
        if ($type === 'rollup') {
            $relation = $database->fields()->where('type', 'relation')->find($config['relation_field_id']);
            if (!$relation) {
                $this->invalid('config.relation_field_id', 'Choose a relation field in this database.');
            }
            $targetDatabase = $this->database($relation->config['target_database_id']);
            if ($config['operation'] !== 'count') {
                $targetField = $targetDatabase->fields()->where('type', 'number')->find($config['target_field_id'] ?? '');
                if (!$targetField) {
                    $this->invalid('config.target_field_id', 'Choose a number field in the related database.');
                }
            } else {
                unset($config['target_field_id']);
            }
        }

        return $config;
    }

    /** @return array{array, array} */
    private function normalizeValues(StructuredDatabase $database, array $input): array
    {
        $fields = $database->fields()->get()->keyBy('id');
        $values = [];
        $relations = [];
        foreach ($input as $fieldId => $value) {
            $field = $fields->get($fieldId);
            if (!$field || $field->type === 'rollup') {
                $this->invalid('values.' . $fieldId, 'Unknown or computed field.');
            }
            if ($field->type === 'relation') {
                $relations[$fieldId] = $this->normalizeRelation($field, $value);
            } else {
                $values[$fieldId] = $this->normalizeValue($field, $value);
            }
        }

        return [$values, $relations];
    }

    private function normalizeValue(StructuredField $field, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        $key = 'values.' . $field->id;
        if ($field->type === 'number') {
            try {
                return DecimalValue::normalize($value);
            } catch (InvalidArgumentException $exception) {
                $this->invalid($key, $exception->getMessage());
            }
        }
        if ($field->type === 'checkbox') {
            if (!is_bool($value)) {
                $this->invalid($key, 'Use true or false.');
            }

            return $value;
        }
        if ($field->type === 'multi_select') {
            if (!is_array($value) || !array_is_list($value) || count($value) > 100 || count(array_unique($value, SORT_REGULAR)) !== count($value)) {
                $this->invalid($key, 'Use a list of distinct options.');
            }
            foreach ($value as $option) {
                if (!is_string($option) || !in_array($option, $field->config['options'], true)) {
                    $this->invalid($key, 'Choose existing options.');
                }
            }

            return $value;
        }
        if (!is_string($value)) {
            $this->invalid($key, 'Use a text value.');
        }
        $rules = match ($field->type) {
            'date' => ['date_format:Y-m-d'],
            'url' => ['max:2048', 'url:http,https'],
            'email' => ['max:254', 'email:rfc'],
            'select' => [Rule::in($field->config['options'])],
            default => ['max:10000'],
        };
        Validator::make(['values' => [$field->id => $value]], [$key => $field->type === 'text' ? $rules : ['required', ...$rules]])
            ->setAttributeNames([$key => $field->name])->validate();

        return $value;
    }

    private function normalizeRelation(StructuredField $field, mixed $value): array
    {
        $key = 'values.' . $field->id;
        Validator::make(['values' => [$field->id => $value]], [
            $key => ['present', 'array', 'list', 'max:100'],
            $key . '.*' => ['required', 'uuid', 'distinct:strict'],
        ])->setAttributeNames([$key => $field->name, $key . '.*' => $field->name])->validate();
        if ($value === []) {
            // An empty visible selection can be saved even when the target book is private.
            // saveRelations() retains links the editor cannot see.
            return [];
        }
        $database = $this->database($field->config['target_database_id']);
        if ($this->visibleRecordQuery($database)->whereIn('id', $value)->count() !== count($value)) {
            $this->invalid('values.' . $field->id, 'Choose visible records from the related database.');
        }

        return $value;
    }

    private function saveRelations(StructuredRecord $record, array $relations): void
    {
        foreach ($relations as $fieldId => $targets) {
            $field = StructuredField::query()->findOrFail($fieldId);
            $visibleIds = $this->visibleRelationTargets($record, $field)->pluck('id')->all();
            // Only replace links visible to the editor, retaining inaccessible links.
            DB::table('structured_relations')->where('source_record_id', $record->id)->where('field_id', $fieldId)
                ->whereIn('target_record_id', $visibleIds)->delete();
            foreach ($targets as $targetId) {
                DB::table('structured_relations')->insertOrIgnore([
                    'source_record_id' => $record->id, 'field_id' => $fieldId, 'target_record_id' => $targetId,
                ]);
            }
        }
    }

    private function snapshot(StructuredRecord $record, string $action): void
    {
        RecordChange::query()->create([
            'database_id' => $record->database_id,
            'record_id' => $record->id,
            'actor_id' => user()->id,
            'action' => $action,
            'snapshot' => [
                'id' => $record->id, 'page_id' => $record->page_id, 'name' => $record->page->name,
                'version' => $record->version, 'values' => $record->values,
                'relations' => $record->relations()->orderBy('field_id')->orderBy('target_record_id')
                    ->get(['field_id', 'target_record_id'])->toArray(),
            ],
        ]);
    }

    private function checkVersion(int $actual, int $expected): void
    {
        abort_if($actual !== $expected, 409, 'This item changed. Reload it before saving.');
    }

    private function validateName(string $name): void
    {
        if (trim($name) === '') {
            $this->invalid('name', 'A name is required.');
        }
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
