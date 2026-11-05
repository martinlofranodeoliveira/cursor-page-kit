# Cursor Page Kit

Original PHP software by [Lofrano Tecnologia](https://lofranotecnologia.com.br). Intended repository: [martinlofranodeoliveira/cursor-page-kit](https://github.com/martinlofranodeoliveira/cursor-page-kit). Version 0.1.0 is unreleased. MIT, copyright 2026 Lofrano Tecnologia.

[Português](README.pt-BR.md)

A small forward keyset-pagination library and PDO/SQLite CLI example. Repeated sort values are resolved by a positive unique ID. It demonstrates one stable read snapshot, not another CRUD dashboard. No Composer packages, runtime APIs, telemetry, network calls, credentials or real personal data are used.

## Install and run

Use 64-bit PHP 8.4+ with PDO_SQLite and SQLite 3.51.3+. Download/extract the project and run from its root; there is no dependency-install step. `proc_open` must be enabled for subprocess tests, but is not used by the library or CLI.

```sh
php -v
php -r 'echo "bits=", PHP_INT_SIZE * 8, " pdo_sqlite=", extension_loaded("pdo_sqlite") ? "yes" : "no", PHP_EOL;'
php tests/run.php
php bin/cursor-page.php demo
php bin/cursor-page.php demo --json
php bin/cursor-page.php --help
```

The official PHP image used by the optional Dockerfile already includes PDO_SQLite; the build verifies it. Docker build needs image access, but tests and demo run offline, unprivileged:

```sh
docker build -t cursor-page-kit .
docker run --rm --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,size=32m cursor-page-kit php tests/run.php
docker run --rm --network none --read-only --tmpfs /tmp:rw,nosuid,nodev,size=32m cursor-page-kit php bin/cursor-page.php demo
```

The image tag is mutable; this is not a claim of byte-reproducible container builds.

## Deterministic demo

The demo creates a private temporary SQLite file, inserts nine invented titles with repeated keys and both signed integer extremes, then uses **one read transaction across all three pages**. It verifies the independently expected ID order, uniqueness and termination. Normal success and catchable failures remove the owned database and sidecars. Abrupt process termination or power loss can leave a temporary file.

Complete human output:

```text
Cursor Page Kit: synthetic snapshot demo
page=1 ids=9,2,4 has_more=true
page=2 ids=1,3,6 has_more=true
page=3 ids=5,7,8 has_more=false
total_items=9 unique_items=9
```

`demo --json` returns `{ "pages": [...], "total_items": 9, "unique_items": 9 }`, with each full page in the contract below. The JSON integers require a lossless 64-bit integer consumer; JavaScript numbers cannot represent every supported value exactly.

## Database and list example

The known schema is an ordinary rowid table in `main`, with exactly these columns in this order and no defaults/generated columns:

```sql
CREATE TABLE items(
    id INTEGER PRIMARY KEY,
    sort_key INTEGER NOT NULL,
    title TEXT NOT NULL
);
CREATE INDEX items_order ON items(sort_key, id);
```

The index is recommended, not required. `id` may additionally declare `NOT NULL`. Rowid aliases are required; views, virtual tables and `WITHOUT ROWID` tables are rejected. Additional unrelated tables and indexes are allowed. `STRICT` is optional. Metadata validation does not certify every constraint or all database contents.

Rows must have a positive integer `id`, a signed 64-bit integer `sort_key`, and UTF-8 TEXT `title` of at most 1,024 bytes. Selected rows, including lookahead, are validated; the whole database is not scanned for validity.

To create a new, small **synthetic** example file, this command refuses an existing path:

```sh
php -r '$h = @fopen("example.sqlite", "x"); if ($h === false) { fwrite(STDERR, "example.sqlite must not exist\n"); exit(2); } fclose($h); $db = new PDO("sqlite:example.sqlite"); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->exec("CREATE TABLE items(id INTEGER PRIMARY KEY, sort_key INTEGER NOT NULL, title TEXT NOT NULL); INSERT INTO items VALUES(1, 0, char(65)), (2, 0, char(66))");'
php bin/cursor-page.php list --db example.sqlite --page-size 2 --json
```

Complete output of that list command:

```json
{
    "items": [
        {
            "id": 1,
            "sort_key": 0,
            "title": "A"
        },
        {
            "id": 2,
            "sort_key": 0,
            "title": "B"
        }
    ],
    "has_more": false,
    "next_cursor": null
}
```

`list --db FILE [--page-size N] [--cursor TOKEN] [--json]` reads one page; default size is 20, allowed sizes are 1..100. Give `--cursor` the exact non-null `next_cursor` returned by an earlier page. Human output quotes titles to neutralize terminal control characters.

List mode resolves an explicitly named regular local file, opens it using SQLite's **READONLY without CREATE** flag and enables `query_only`. It never creates, migrates or writes database content. Missing, non-SQLite and unexpected-schema files fail. SQLite can participate in ordinary locking/WAL reader coordination; this is not a filesystem sandbox or an immutable snapshot-file reader. Use a trusted local filesystem and trusted database files, not attacker-supplied SQLite binaries. Symlinks are resolved. Filesystem replacement races are outside the contract.

Exit status is 0 for a valid page, successful demo or help; 2 for usage, runtime, I/O, schema, data or cursor errors. Diagnostics go to stderr without paths, row titles, full cursors or raw PDO errors. Successful page output intentionally contains titles and continuation cursors.

## Library usage

```php
<?php
declare(strict_types=1);
require 'src/bootstrap.php';

use CursorPageKit\Cursor;
use CursorPageKit\Pager;
use CursorPageKit\SqliteDatabase;

$token = Cursor::encode(-4, 12);
$position = Cursor::decode($token); // sortKey=-4, id=12
$pdo = SqliteDatabase::openReadOnly('example.sqlite');
$pdo->beginTransaction();
try {
    $page = (new Pager($pdo))->page(2); // optional second argument: cursor
    $pdo->commit();
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}
```

`KeysetPredicate::after(?CursorPosition)` exposes `sql()` and `bind(PDOStatement)` for the fixed `(sort_key, id)` column names. It accepts no arbitrary SQL or identifiers. All cursor values and limits are bound as PDO integers.

## Cursor and page contract

A v1 cursor is strict **unpadded base64url** of exactly:

```json
{"v":1,"sort_key":0,"id":1}
```

Key order, whitespace absence and canonical decimal integers are mandatory. Decoded bytes must equal a canonical re-encoding; duplicate keys are therefore rejected too. Maximum token size: 128 ASCII bytes. Sort keys range from -9223372036854775808 to 9223372036854775807; IDs from 1 to 9223372036854775807. Padding, nonzero unused base64 bits, missing/extra properties, booleans, floats, alternate spellings and overflows are rejected.

Pages always have `items`, `has_more` and `next_cursor`. Items are ordered by `sort_key ASC, id ASC`. The query reads size plus one; the cursor references the last **returned** item only when another row exists. Final/empty pages have `has_more: false` and `next_cursor: null`. An absent cursor starts traversal; an empty string is invalid. Stopping on null is essential: passing null again restarts.

**Cursors are transport data, not authentication, encryption or authorization.** Anyone can construct a valid tuple. An application must authorize every query and scope it to the correct dataset. This v1 cursor has no tenant, filter, expiry or snapshot identity.

**Snapshot limitation:** SQLite pins a deferred read transaction's snapshot at its first read. Keep all page reads in one caller-owned transaction for the demonstrated stable traversal. Separate CLI list invocations use separate transactions. Inserts, deletes or ordering updates between transactions can change membership/order and cause omissions or reappearances; exactly-once traversal under concurrent mutation is not promised.

See [contract](docs/contract.md), [design and trade-offs](docs/design.md), [security](SECURITY.md) and [contributing](CONTRIBUTING.md). This project implements the documented SQLite subset, not a general SQL pagination standard or production-readiness certification.

## Database encoding

Only UTF-8 SQLite databases are supported. UTF-16 databases are rejected before rows are read: SQLite encodes a TEXT-to-BLOB cast using its database encoding, while this tool bounds and emits UTF-8 title bytes. This restriction prevents silently altered titles.

## SQLite runtime

Use the [checksum-pinned build and SQL qualification](docs/sqlite-runtime.md). The application requires actual linked SQLite 3.51.3+; the supplied image uses 3.54.1. Build with `docker build -t cursor-page-kit-sqlite .`, then run the documented test/demo argv with `docker run --rm --network none cursor-page-kit-sqlite <argv>`. Native installations must independently provide the supported SQLite range.
