# Public contract — v1 / unreleased 0.1.0

## Runtime and loading

Require `src/bootstrap.php`. It requires PHP 8.4+ with 64-bit integers. The codec/predicate have no database dependency; database operations require PDO_SQLite backed by SQLite 3.37+. No Composer dependency or runtime network access is required.

Public classes live in `CursorPageKit`. Validation and wrapped storage failures throw `PageKitException`. Its message is safe for diagnostics; a chained exception may contain sensitive database details and must not be displayed to untrusted users. Unrecoverable runtime conditions can still throw other PHP exceptions/errors.

## Tuple and cursor

`new CursorPosition(mixed $sortKey, mixed $id)` produces a readonly object with integer `sortKey` and `id`. Both arguments must already be PHP integers; no scalar coercion is accepted. Sort key range is [-9223372036854775808, 9223372036854775807]. ID range is [1, 9223372036854775807].

`Cursor::encode(mixed $sortKey, mixed $id): string` validates those same ranges and emits unpadded base64url. `Cursor::decode(mixed $token): CursorPosition` accepts only strings and validates the complete transport.

Decoded payload grammar, expressed as a byte template:

```text
{"v":1,"sort_key":SORT,"id":ID}
```

SORT and ID are canonical decimal integers in the above ranges. Zero is `0`; negative zero, plus signs, leading zeros, decimal points and exponents are forbidden. Exact property order is mandatory. There is no whitespace, BOM or trailing data. Decoded bytes must equal the canonical re-encoding. Duplicate, extra and missing keys are rejected. Booleans, strings, floats and JSON containers cannot stand in for integers. Version must be the integer 1.

Transport alphabet is ASCII A-Z, a-z, 0-9, hyphen and underscore. Length is 1..128 bytes. Padding, whitespace, invalid length, alternate alphabets and nonzero unused base64 bits are rejected. Implementations must not accept a noncanonical transport merely because it decodes to valid JSON.

Tokens are opaque application transport, not authorization, authentication or encryption. They are forgeable and have no expiration, dataset, filter, tenant or snapshot binding. A syntactically valid cursor is not evidence that the tuple exists or that its holder may access any item.

## Bound predicate

`KeysetPredicate::after(?CursorPosition $position): KeysetPredicate` returns an immutable predicate. `sql(): string` returns `1 = 1` for null, otherwise `(sort_key, id) > (:cursor_sort_key, :cursor_id)`. `bind(PDOStatement $statement): void` binds each non-null cursor component with `PDO::PARAM_INT`.

Only fixed, unqualified `sort_key` and `id` names are supported. Use with ascending tuple ordering and a compatible FROM clause. Do not reuse these placeholder names for other meanings. No identifier or SQL-fragment input is accepted.

## Database

`SqliteDatabase::openReadOnly(string $path): PDO` requires an explicitly named existing readable regular local file. Wrapper URLs and NUL bytes are rejected. Symlinks resolve to their target. The opener uses an absolute filesystem DSN, SQLite READONLY without CREATE, `query_only`, exception mode and a one-second busy timeout, then checks schema. It neither creates nor migrates nor writes database content. SQLite locking/WAL coordination and filesystem access times are outside the no-content-write guarantee. Trust the database binary and local filesystem; race-free path confinement is not provided.

`SqliteDatabase::assertSchema(PDO $pdo): void` checks the documented schema and configures exception mode and non-stringified numeric fetches. `Pager::__construct(PDO $pdo)` invokes this check. A supplied PDO may otherwise be writable; the pager itself issues only SELECT statements and schema introspection.

Required table in `main`:

```sql
CREATE TABLE items(id INTEGER PRIMARY KEY, sort_key INTEGER NOT NULL, title TEXT NOT NULL);
```

Exactly these three columns in this order are accepted, with no defaults/hidden/generated columns. ID may additionally be declared NOT NULL. It must be a rowid alias without a separate primary-key index; `INTEGER PRIMARY KEY DESC`, views, virtual tables and WITHOUT ROWID tables are not accepted. Optional STRICT and additional constraints are not exhaustively interpreted. Other tables/indexes are allowed. Recommended optional index:

```sql
CREATE INDEX items_order ON items(sort_key, id);
```

Data requirements: positive integer ID, signed integer sort key, non-null TEXT title containing valid UTF-8 of at most 1,024 bytes. Empty titles are allowed. Only encountered rows, including lookahead, are data-validated. Invalid rows outside a requested range may remain undetected.

## Pager

`Pager::page(mixed $pageSize = 20, mixed $cursor = null): array` requires an actual integer size in 1..100. Null cursor starts at the beginning; other values must be valid cursor strings. Empty string is not absence. A valid tuple need not correspond to an existing row.

Return fields, always present in this order:

| Field | Type | Meaning |
| --- | --- | --- |
| items | list of objects/associative arrays | Each has `id: int`, `sort_key: int`, `title: string`, in that order |
| has_more | bool | A valid lookahead row exists after the returned items |
| next_cursor | string or null | Last returned tuple when has_more is true; null otherwise |

Rows use `ORDER BY sort_key ASC, id ASC`, strictly after the cursor. All values, including size plus one, are bound. The query fetches at most size plus one rows. There are no caller-controlled identifiers, SQL fragments or filters. A page is returned only after all candidate rows validate. Titles are never silently truncated. There is no total count or backward traversal.

An empty page has an empty items list, false has_more and null next_cursor. An exact final full page also has false/null. Stop at null; passing null into another call restarts traversal. Page size can change between calls without changing the tuple ordering semantics within a stable snapshot.

The pager does not begin/end transactions or preserve a snapshot itself. For a multi-page stable traversal, the caller must hold one consistent read transaction across the reads. SQLite pins a deferred transaction's snapshot on its first read. Mutations between separate transactions may cause omissions, reappearances or changed membership; no exactly-once guarantee under concurrent mutation is made.

## CLI

```text
php bin/cursor-page.php --help
php bin/cursor-page.php demo [--json]
php bin/cursor-page.php list --db FILE [--page-size N] [--cursor TOKEN] [--json]
```

No positional database path is accepted. Flags may appear in any order after the command but may not repeat. Unknown options, missing values and unrelated positional arguments fail. List page-size text must be canonical decimal 1..100 (not `01`, `+1` or `1.0`). List default is 20. Demo has fixed size three and accepts only `--json`.

List reads one page in a single read-only transaction. Each invocation uses a new transaction. JSON list output is the page object; human output begins with `items=N`, then one line per item with JSON-quoted title, then has_more and next_cursor lines. JSON escapes control characters and preserves integer text; consumers must avoid lossy numeric parsing.

Demo JSON has `pages` (three page objects), `total_items` (9) and `unique_items` (9). Human output summarizes each page's IDs and final totals. The temporary nine-item fixture has repeated sort keys and integer extremes, and its complete expected ID sequence is `[9,2,4,1,3,6,5,7,8]`. It is verified within one multi-page read transaction. Normal and catchable error paths clean up its owned files; abrupt termination is outside that guarantee.

Successful data/help output goes to stdout. Diagnostics go to stderr with fixed messages, without full cursors, row titles, paths, raw PDO messages or stack traces. Successful output is not a diagnostic and intentionally exposes returned items and cursors.

Exit codes:

* 0: valid page (including empty), verified demo, or help.
* 2: usage, environment, I/O, SQLite/schema/data, cursor or other operation error.

## Compatibility policy

The v1 payload and page fields above are the intended stable contract. A future incompatible cursor payload needs a distinct version and explicit handling; silently accepting unknown versions is forbidden. Version 0.1.0 remains unreleased and no general SQL/JSON/base64 ecosystem compliance beyond this documented subset is claimed.

## Storage encoding requirement

The database encoding must be UTF-8. Both `assertSchema` and the `Pager` constructor reject UTF-16LE/BE databases before interpreting title bytes. The same restriction applies to the read-only list CLI. The schema is otherwise unchanged; the opener never converts or migrates a file.
