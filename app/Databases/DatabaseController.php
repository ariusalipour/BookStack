<?php

namespace BookStack\Databases;

use BookStack\Entities\Models\Book;
use BookStack\Http\ApiController;
use BookStack\Permissions\Permission;
use Illuminate\Http\Request;

/**
 * Experimental page-backed structured databases. Requires STRUCTURED_DATABASES_ENABLED.
 * Property, relation and rollup values follow the schema documented in this fork's development guide.
 */
class DatabaseController extends ApiController
{
    protected array $rules = [
        'store' => ['book_id' => ['required', 'integer'], 'name' => ['required', 'string', 'max:255']],
        'storeField' => [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:text,number,checkbox,date,select,multi_select,url,email,relation,rollup'],
            'version' => ['required', 'integer', 'min:1'],
            'config' => ['sometimes', 'array'],
        ],
        'storeRecord' => ['name' => ['required', 'string', 'max:255'], 'values' => ['sometimes', 'array']],
        'updateRecord' => [
            'name' => ['required', 'string', 'max:255'],
            'values' => ['sometimes', 'array'],
            'version' => ['required', 'integer', 'min:1'],
            'page_revision' => ['required', 'integer', 'min:1'],
        ],
        'destroyRecord' => ['version' => ['required', 'integer', 'min:1']],
    ];

    public function __construct(protected DatabaseService $databases)
    {
        $this->middleware(function (Request $request, $next) {
            abort_unless(config('structured-databases.enabled'), 404);
            $this->preventGuestAccess();

            return $next($request);
        });
    }

    /** List databases in books visible to the current authenticated user. */
    public function index(Request $request)
    {
        $databases = $this->databases->visibleDatabases();
        if ($this->isJson($request)) {
            return response()->json(['data' => $databases->map(fn ($database) => $this->databaseData($database))]);
        }

        $books = Book::visible()->whereNotIn('id', StructuredDatabase::query()->select('book_id'))->orderBy('name')->get()
            ->filter(fn (Book $book) => userCan(Permission::BookUpdate, $book));
        $this->setPageTitle(trans('databases.title'));

        return view('databases.index', [
            'databases' => $databases,
            'books' => $books,
            'canCreate' => $books->isNotEmpty(),
        ]);
    }

    /** Create a database in a book the current user can update. One database is supported per book. */
    public function store(Request $request)
    {
        $input = $request->validate($this->rules['store']);
        $database = $this->databases->createDatabase($input);

        return $this->isJson($request)
            ? response()->json($this->databaseData($database), 201)
            : redirect(url('/databases/' . $database->id));
    }

    /** Read a database schema and its visible records, including permission-filtered relations and rollups. */
    public function show(Request $request, string $databaseId)
    {
        $database = $this->databases->database($databaseId);
        $search = $request->validate(['search' => ['nullable', 'string', 'max:200']])['search'] ?? '';
        if ($this->isJson($request)) {
            return response()->json($this->databases->export($database));
        }

        $fields = $database->fields()->orderBy('position')->get();
        $sourceRecords = $this->databases->records($database, $search)->keyBy('id');
        $records = $this->databases->serializeRecords($database, $sourceRecords)->map(function (array $record) use ($sourceRecords) {
            return $record + ['can_edit' => userCan(Permission::PageUpdate, $sourceRecords->get($record['id'])->page)];
        });
        $databases = $this->databases->visibleDatabases();
        $this->setPageTitle($database->name);

        return view('databases.show', [
            'database' => $database,
            'fields' => $fields,
            'records' => $records,
            'targets' => $this->relationTargets($fields),
            'databases' => $databases,
            'relatedFields' => $databases->mapWithKeys(fn ($db) => [$db->id => $db->fields()->orderBy('position')->get()]),
            'canManage' => userCan(Permission::BookUpdate, $database->book),
            'canCreate' => userCan(Permission::PageCreate, $database->book),
            'search' => $search,
        ]);
    }

    /** Add a typed field using the current database schema version. Stale versions return 409. */
    public function storeField(Request $request, string $databaseId)
    {
        $database = $this->databases->database($databaseId);
        $input = $request->validate($this->rules['storeField']);
        // HTML forms submit a comma separated option list; JSON clients submit an array.
        if (!$this->isJson($request)) {
            $config = $input['config'] ?? [];
            $keys = match ($input['type']) {
                'relation' => ['target_database_id'],
                'rollup' => ['relation_field_id', 'target_field_id', 'operation'],
                'select', 'multi_select' => ['options'],
                default => [],
            };
            $input['config'] = array_intersect_key($config, array_flip($keys));
            if (is_string($input['config']['options'] ?? null)) {
                $input['config']['options'] = array_values(array_filter(array_map('trim', explode(',', $input['config']['options'])), fn ($value) => $value !== ''));
            }
        }
        $field = $this->databases->addField($database, $input);

        return $this->isJson($request)
            ? response()->json($field, 201)
            : redirect(url('/databases/' . $database->id));
    }

    /** Create a record and its normal BookStack page. Values are keyed by field UUID; relation inputs are record UUID lists. */
    public function storeRecord(Request $request, string $databaseId)
    {
        $database = $this->databases->database($databaseId);
        $record = $this->databases->createRecord($database, $this->recordInput($request, $database));

        return $this->isJson($request)
            ? response()->json($this->databases->serializeRecord($record), 201)
            : redirect(url('/databases/' . $database->id));
    }

    public function editRecord(string $databaseId, string $recordId)
    {
        $database = $this->databases->database($databaseId);
        $record = $this->databases->record($database, $recordId);
        $this->checkOwnablePermission(Permission::PageUpdate, $record->page);
        $fields = $database->fields()->orderBy('position')->get();
        $this->setPageTitle($record->page->name);

        return view('databases.edit-record', [
            'database' => $database,
            'record' => $this->databases->serializeRecord($record),
            'fields' => $fields,
            'targets' => $this->relationTargets($fields),
            'canEdit' => true,
            'canDelete' => userCan(Permission::PageDelete, $record->page),
        ]);
    }

    /** Update a record using current property version and page revision tokens. Omitted properties are preserved. */
    public function updateRecord(Request $request, string $databaseId, string $recordId)
    {
        $database = $this->databases->database($databaseId);
        $record = $this->databases->record($database, $recordId);
        $input = $this->recordInput($request, $database);
        $input += $request->validate($this->rules['updateRecord']);
        $record = $this->databases->updateRecord($database, $record, $input);

        return $this->isJson($request)
            ? response()->json($this->databases->serializeRecord($record))
            : redirect(url('/databases/' . $database->id));
    }

    /** Move the record's page to the recycle bin while retaining structured data for restoration. */
    public function destroyRecord(Request $request, string $databaseId, string $recordId)
    {
        $database = $this->databases->database($databaseId);
        $record = $this->databases->record($database, $recordId);
        $input = $request->validate($this->rules['destroyRecord']);
        $this->databases->deleteRecord($database, $record, $input['version']);

        return $this->isJson($request) ? response()->noContent() : redirect(url('/databases/' . $database->id));
    }

    /** Download a JSON property/link snapshot of visible records. This does not include page bodies or attachments. */
    public function export(string $databaseId)
    {
        $database = $this->databases->database($databaseId);

        return response()->streamDownload(function () use ($database) {
            echo json_encode($this->databases->export($database), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }, 'database-' . $database->id . '.json', ['Content-Type' => 'application/json']);
    }

    protected function recordInput(Request $request, StructuredDatabase $database): array
    {
        $input = $request->validate($this->rules['storeRecord']);

        if (!$this->isJson($request)) {
            foreach ($database->fields as $field) {
                if (!array_key_exists($field->id, $input['values'] ?? [])) {
                    continue;
                }
                $value = $input['values'][$field->id];
                if ($field->type === 'checkbox' && in_array($value, ['0', '1', 0, 1], true)) {
                    $input['values'][$field->id] = (bool) $value;
                } elseif ($value === null || $value === '') {
                    $input['values'][$field->id] = in_array($field->type, ['relation', 'multi_select'], true) ? [] : null;
                }
            }
        }

        return $input;
    }

    protected function relationTargets($fields): array
    {
        $targets = [];
        $visible = $this->databases->visibleDatabases()->keyBy('id');
        foreach ($fields as $field) {
            if ($field->type !== 'relation') {
                continue;
            }
            $target = $visible->get($field->config['target_database_id']);
            $targets[$field->id] = $target ? $this->databases->records($target)->map(fn ($record) => [
                'id' => $record->id,
                'name' => $record->page->name,
                'url' => $record->page->getUrl(),
            ])->all() : [];
        }

        return $targets;
    }

    protected function databaseData(StructuredDatabase $database): array
    {
        return ['id' => $database->id, 'book_id' => $database->book_id, 'name' => $database->name, 'version' => $database->version];
    }

    protected function isJson(Request $request): bool
    {
        return $request->wantsJson() || $request->is('api/*');
    }
}
