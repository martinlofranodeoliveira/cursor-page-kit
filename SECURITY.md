# Security notes and reporting

Version 0.1.0 is unreleased. There is no published support window or security certification.

## Intended boundaries

- Cursors are unsigned, unencrypted, forgeable tuple transport. Validation is not authorization. Apply access control and dataset scoping to every application query.
- The v1 cursor does not bind a tenant, filter, snapshot, expiry or permission set. Never treat possession of a cursor as a grant.
- List uses SQLite READONLY without CREATE and `query_only`; the library pager executes fixed SELECT queries with bound values. A caller-provided PDO may be writable.
- Read-only access is not a sandbox for malicious SQLite binaries, unsafe native extensions, filesystem replacement races or untrusted local users. Use a trusted local filesystem and database source. Symlinks resolve to their targets.
- SQLite may coordinate WAL readers and locks through sidecars. The guarantee is no database-content writes, not a promise that filesystem metadata never changes.
- Size limits bound cursor parsing, PHP row payloads and page counts. They do not impose a SQLite execution deadline or hard database-engine memory limit. Prefer the documented composite index.
- Titles can contain arbitrary valid UTF-8, including control characters. CLI human output JSON-quotes them; downstream HTML/terminal consumers must escape for their own context.
- CLI diagnostics deliberately omit paths, row titles, tokens and raw storage messages. Library exception chains can contain storage details; do not expose them to end users.
- Snapshot consistency requires one read transaction. Separate transactions do not provide exactly-once pagination under concurrent mutation.

## Reporting

Do not put credentials, real rows, private database files or full sensitive cursors in a public issue. Use GitHub private vulnerability reporting at:

https://github.com/martinlofranodeoliveira/cursor-page-kit/security/advisories/new

For general contact, visit https://lofranotecnologia.com.br . No particular response time, SLA or reporting service availability is promised.

Include affected source version, PHP/SQLite versions, a minimal synthetic reproducer, expected versus observed behavior and the security impact. Non-sensitive correctness reports may use an ordinary repository issue.

No telemetry, tracking, secret collection or runtime network calls are implemented.
