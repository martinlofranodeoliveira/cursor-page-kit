# Changelog

## 0.1.0 — Unreleased

### Added

- Original PHP 8.4+ forward composite-key pagination core for 64-bit runtimes.
- Canonical v1 JSON/base64url cursor codec with explicit integer and transport limits.
- Immutable positions and reusable integer-bound SQLite tuple predicate.
- Fixed-schema PDO pager with lookahead, validated data and bounded title transfer.
- Read-only local-file list CLI and deterministic temporary-database snapshot demo.
- Synthetic regression, adversarial, read-only and WAL snapshot tests.
- English/Portuguese READMEs, contract/design notes and non-publishing CI checks.

No release, deployment, benchmark or production-readiness claim is made.

* Qualify actual linked SQLite 3.51.3+ and provide a checksum-pinned SQLite 3.54.1 runtime build; preserve journal and transaction semantics.
