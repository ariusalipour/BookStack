# Structured databases in this fork

Status: experimental, opt-in. Based on BookStack v26.09.2. This feature is maintained in this fork, independently of upstream BookStack.

## Purpose and first milestone

Keep structured operational records alongside their documentation without turning page HTML into the data store. Each database belongs to one existing book. Creating a record creates a normal published page through BookStack's page repository. Its body stays editable in the normal page editor; its properties and links live in dedicated tables.

The first milestone implements:

- Text, exact decimal number, checkbox, date, select, multi-select, URL and email fields.
- Many-to-many links to records in a selected database, including the same database.
- Relation rollups: count, sum, average, minimum and maximum.
- Database listing, record table, name search, record creation and property editing.
- Authenticated JSON endpoints and a permission-filtered JSON snapshot download.
- Stable database, field and record UUIDs; version checks for schema and property edits.
- BookStack page permissions, search, revisions, page editor, deletion and recycle-bin restoration.

Relations are directional in this milestone. Reciprocal properties and reverse-relation editing are planned. Numbers accept integers or decimal strings with up to 18 integer and 6 fractional digits; send fractional JSON values as strings. Arithmetic is exact and averages round half away from zero to 6 decimal places. Dates are calendar dates without a time or timezone.

## Enable and use

Back up the full application database and files. Install the fork using the normal BookStack installation process, run `php artisan migrate --force`, and set `STRUCTURED_DATABASES_ENABLED=true` in the application environment. The flag defaults to false. Disabling it hides the interface and endpoints but preserves stored data.

Use **Databases** in the header. Choose a book you can update, name its database, add fields, then add records. A record's title opens its BookStack page; **Edit properties** opens the property form. The owning book also links to its database.

Creating a database or adding fields requires update permission on its book. Creating records requires page-create permission in the book. Editing or deleting records requires the corresponding permission on each page. Read access requires both the database's book and the record's page to be visible. Guest access is disabled for this feature.

The relation picker contains visible records only. Relations and rollups are recalculated for the current reader: inaccessible, draft, moved or deleted pages are excluded. A total can therefore differ between readers. Editing visible relation selections retains inaccessible stored links, preventing an editor from unknowingly erasing them. JSON field definitions redact identifiers for inaccessible target databases and their fields.

## JSON endpoints

These routes use BookStack's existing token authentication and API rate limits under `/api`. Session-authenticated requests to `/databases` also return JSON when requested with `Accept: application/json`; session writes require CSRF protection.

| Method | Path | Purpose |
|---|---|---|
| GET / POST | `/api/databases` | List visible databases / create one (`book_id`, `name`) |
| GET | `/api/databases/{database}` | Read schema and visible records |
| POST | `/api/databases/{database}/fields` | Add a field (`name`, `type`, `config`, current schema `version`) |
| POST | `/api/databases/{database}/records` | Create a record (`name`, `values`) |
| PUT | `/api/databases/{database}/records/{record}` | Edit properties/title (`name`, `values`, `version`, `page_revision`) |
| DELETE | `/api/databases/{database}/records/{record}` | Move record page to recycle bin (`version`) |
| GET | `/api/databases/{database}/export` | Download schema and visible property/link values as JSON |

`values` maps field UUIDs to typed values. Relation values are arrays of target record UUIDs. Relation responses contain visible `{id, name, url}` objects. Select fields use `config.options` (an array of strings). Relations use `config.target_database_id`. Rollups use `config.relation_field_id`, `config.operation` and, for numeric operations, `config.target_field_id` referencing a number field in the related database. Rollups are read-only.

Updates preserve omitted fields. Send `null` to clear scalar values and `[]` to clear visible relation or multi-select values. Invalid inputs return 422; stale schema or record/page tokens return 409; inaccessible resources return 404 and denied writes return 403. Reload before retrying a conflicted edit.

## Storage, backup and lifecycle

The new `structured_*` tables hold database definitions, fields, records, relation edges and internal immutable record-change snapshots. Foreign keys reference BookStack's composite entity key. Page bodies remain in BookStack's own tables. Page deletion hides the record and its links from readers; restoring the page restores its registration. Moving a record outside its configured book hides its registration until the page is moved back. Permanent entity deletion cascades the associated structured data and links.

Property history is separate from BookStack page revisions. Restoring a native page revision restores the page body/title, not its structured properties. The initial history table has no public history/restore interface; its snapshots are purged with the record on permanent deletion. The native page-copy and book-copy tools do not copy schemas, registrations or property data.

**BookStack's ordinary ZIP, Markdown, HTML and PDF exports do not include these tables.** The custom JSON download is a property/link snapshot, not a complete restorable backup: it omits page bodies, attachments, inaccessible rows/links and internal history, and this milestone has no importer. Use a full SQL dump plus BookStack files for disaster recovery. Do not roll back the new migration after creating real data: its `down()` drops all structured tables.

## Remaining milestones before replacing a workspace

1. **Schema lifecycle and views:** safe field renaming, ordering, deletion and type conversion; database settings; pagination and indexed property filters; sorting, saved table/board/calendar views; required and unique fields; people/status/date-time types. Record/relation serialization is batched now; listings, pickers and exports still materialize their result sets in memory, so realistic large-dataset verification remains a release gate.
2. **Relations and calculations:** reciprocal relation properties and reverse editing, one-to-one/one-to-many cardinality, nested relation handling, additional rollups and a constrained formula language. Formula parsing must use a whitelist of typed operations, dependency-cycle detection and resource limits, never arbitrary PHP, SQL or JavaScript evaluation.
3. **Integrated lifecycle:** permission-safe property history and restore, native copy/export/import support, property context on normal pages, structured-value search, schema audit history, custom API documentation and update compatibility checks.
4. **Migration tooling:** inventory supported source schemas; map source IDs to stable destination UUIDs; import all schemas first, records/content second, relations third; reconcile formulas and unsupported types; import files; rewrite links; produce per-database count/type/link and representative calculation reports. Imports must be repeatable and resumable with a dry run and no source writes.
5. **Release gate:** test MySQL and the deployed MariaDB version, existing installation upgrades, migration rollback on a disposable database, backup/restore with structured data, permission changes, browser/keyboard/mobile behavior, concurrent edits and realistic dataset sizes. Audit existing dependency findings before exposing the fork as a production replacement.

Whole-workspace migration is blocked until the source schemas, formulas, relations, views, files and publication integrations have explicit supported mappings or accepted alternatives. Keep private workspace inventories and source identifiers outside this public repository.

## Verification

Set up the existing BookStack development and test databases as described in `development.md` and `php-testing.md`. Run `composer refresh-test-database`, then `vendor/bin/phpunit tests/Databases`. Check PHP style with `vendor/bin/phpcs`, static types with `vendor/bin/phpstan analyse app/Databases`, and build the existing assets with `npm ci && npm run build`. The feature test suite covers permissions, hidden relations/aggregates, typed validation, concurrency, export and recycle-bin restoration. Use the normal upstream entity suites to detect regressions in shared behavior.
