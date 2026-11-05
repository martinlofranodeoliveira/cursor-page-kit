# Contributing

Thank you for considering a contribution to Lofrano Tecnologia's Cursor Page Kit.

## Local checks

Use 64-bit PHP 8.4+, PDO_SQLite and SQLite 3.51.3+. Enable `proc_open` for tests. No Composer installation is required.

```sh
php tests/run.php
php bin/cursor-page.php demo
php bin/cursor-page.php demo --json
find src bin tests -name '*.php' -exec php -l {} \;
```

The optional Docker commands are in the READMEs. CI performs checks only; it does not publish packages, images or releases, and does not deploy remotely.

## Change guidelines

- Keep code original and MIT-compatible. Never submit private client code, credentials, production dumps or personal data.
- Use invented synthetic fixtures only. Do not fabricate adoption, performance, certification or deployment claims.
- Maintain strict types, explicit integer validation, fixed SQL identifiers and bound values.
- Add a regression test with an independent expected result for each bug fix. Include malformed input and boundary cases where appropriate.
- Preserve the documented v1 byte contract. Discuss incompatible changes before implementing them.
- Keep dependencies and runtime surface small. Explain any new resource cost or trust boundary.
- Update both READMEs and the contract/design notes when behavior changes; record unreleased changes in CHANGELOG.md.
- Do not add telemetry, model/API dependencies, publish/release workflows or remote-deployment automation.

Use repository issues for ordinary bugs and proposals, including the runtime versions, a minimal synthetic reproducer and expected behavior. Follow SECURITY.md for sensitive reports. No contributor response-time commitment is implied.

Contributions are offered under the project's MIT license. Preserve the 2026 Lofrano Tecnologia copyright notice and credit any future third-party material accurately.
