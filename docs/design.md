# Design rationale

## Scope

Cursor Page Kit separates a strict transport codec, an immutable tuple, a bound predicate and a fixed-schema SQLite pager. PHP's standard JSON/base64 facilities and PDO are sufficient; no autoloader package or external runtime service is needed. The bootstrap includes a fixed list of source files and rejects non-64-bit or older PHP runtimes.

This is forward-only pagination. There are no arbitrary table names, filters, sort expressions, offsets, reverse cursors or total-count queries. These omissions keep the query/transport contract small enough to inspect and test. Only the documented SQLite subset is supported.

## Ordering and lookahead

`sort_key` alone is not unique. Appending the positive rowid alias `id` creates a total tuple order. The strict row-value predicate `(sort_key, id) > (:cursor_sort_key, :cursor_id)` advances past exactly the previous returned tuple. Ordering both components ascending makes it compatible with the recommended composite index.

Each query binds a limit of page size plus one. The extra item establishes whether another page exists; it is validated but not returned. A non-final cursor identifies the last returned item, so lookahead is not skipped. Exact boundaries therefore produce a final null cursor without an unnecessary empty-page request. A caller may nevertheless present any valid tuple, including one absent from the table.

## Canonical transport

JSON integers are decoded with `JSON_BIGINT_AS_STRING`. Values outside PHP's integer range become strings rather than rounded floats and are rejected. Explicit `is_int` checks prevent PHP bool/float/string confusion even for callers without strict typing.

Both base64url and JSON must reproduce the received bytes exactly. This rejects unused base64 bits, padding, alternate JSON spellings, whitespace, reordered keys and duplicate properties. A 128-byte pre-decode bound limits allocation; JSON depth is limited to four. An immutable position validates its invariants independently of the codec.

The token is deliberately unsigned. Signing would require a key-management and context-binding contract that this local example does not implement. Opacity means callers must not depend on internals; it does not mean secrecy or tamper resistance.

## Database access and errors

The public read-only opener resolves a regular local file, uses SQLite's READONLY flag without CREATE, adds `query_only`, applies a one-second busy timeout and validates metadata. The pager also validates schema for callers supplying their own PDO connection. It sets exception mode and disables stringified fetches on that connection. It does not open or commit application transactions.

Metadata checks reject unrelated schemas, views, virtual tables, non-rowid tables, extra/generated columns, defaults, wrong types, nullable ordering/title columns and separate primary-key indexes. They do not parse or certify all possible CHECK/UNIQUE definitions. Only `main.items` is queried, so a temporary table cannot shadow the selected dataset. SELECT does not invoke table write triggers.

Selected data must satisfy the integer, positive-ID, text, UTF-8 and title-byte limits. SQLite dynamic typing makes these runtime checks necessary even after metadata validation. A 1,025-byte BLOB prefix is selected to detect overlong titles without transferring an unbounded title to PHP. Invalid data is an error, never silently truncated or skipped.

Public operational errors are `PageKitException` with fixed messages. A previous PDO exception may exist for library debugging; CLI output never prints it. CLI buffers its small result until the read succeeds, escapes human titles with JSON quoting and emits generic diagnostics for unexpected exceptions.

## Snapshot ownership

SQLite starts a deferred transaction at `beginTransaction`; its first read pins the snapshot. Demo initialization finishes before opening one transaction for all page reads. Its assertion compares against a literal expected ID sequence, not a re-sorted copy of the queried rows.

The caller owns transaction lifetime in the library. List establishes a single-page read transaction, not a server-side session that survives invocations. A cursor contains no snapshot identity. Separate transactions can observe inserts, deletions and movement of rows across the cursor. Long read transactions also retain SQLite resources; real applications must choose an appropriate duration and concurrency strategy.

## Resource limits and trust boundary

* Page size: 1..100; at most 101 candidate rows fetched per call.
* Cursor: at most 128 ASCII bytes; decoded JSON at most 96 bytes.
* Title returned to PHP: at most 1,025 bytes per candidate; accepted titles at most 1,024.
* Demo: nine fixtures, at most nine iterations, expected three pages.
* Read-only opener: one-second SQLite busy timeout. This is not a statement execution deadline.
* Tests: subprocess output capped at 64 KiB and each subprocess given a ten-second deadline. CI jobs and steps also have timeouts.

There is no hard SQLite VM instruction, database-size, query-sort-memory or wall-clock bound in PDO. Without the recommended index SQLite may scan/sort the database. SQLite may need to read large source records internally even though PHP transfers are bounded. This is not a sandbox for hostile/corrupt database binaries or a defense against filesystem replacement races. WAL readers may coordinate through sidecars. Filesystem access times and locks are not promised unchanged.

Demo cleanup runs in `finally` and closes statements/connections before deleting only its own temporary path and known sidecars. Power loss and uncatchable termination cannot be cleaned up by PHP. No user path is deleted.

## Regression strategy

Tests use literal transport bytes, fixed expected tuple sequences and adversarial JSON/SQL-looking values. Coverage includes empty/single/exact-boundary/lookahead/multi-page traversals, signed integer extremes, continuation beyond the end, invalid selected data, schema rejection, actual WAL snapshot isolation and an OS-level read-only connection even after disabling `query_only`. CLI tests compare database hashes, test missing-file noncreation, verify diagnostic redaction and inspect a private temporary directory after the demo. All fixtures are synthetic; no benchmark or deployment claim is made.

## References consulted

* https://www.php.net/manual/en/pdo.prepared-statements.php
* https://www.php.net/manual/en/ref.pdo-sqlite.php
* https://www.sqlite.org/rowvalue.html
* https://github.com/docker-library/php

These describe underlying facilities; the implementation and tests here are original.

## Bounded title reads and encoding

SQLite TEXT-to-BLOB casts use the database encoding ([SQLite CAST documentation](https://www.sqlite.org/lang_expr.html#castexpr)). Before using bounded blob reads, schema validation requires UTF-8. Regression tests cover both UTF-16 byte orders and confirm that list mode rejects them without changing database bytes.
