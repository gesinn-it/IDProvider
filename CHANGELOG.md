# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `update.php` failing with MySQL error 1170 (`BLOB/TEXT column 'prefix' used in key
  specification without a key length`) when upgrading a wiki that already had
  `idprovider_increments.prefix` as a legacy blob column from before 3.0. The schema
  updater now uses `modifyExtensionField()` instead of `addExtensionField()`, so the
  column is migrated to `varbinary(255) NOT NULL` even when it already existed, before
  the `UNIQUE` index is added. [`8465b5d`](https://github.com/gesinn-it-pub/IDProvider/commit/8465b5d)

## [3.0.1] - 2026-08-12

### Added

- Phan static analysis, wired into CI as a dedicated step on the coverage matrix row. [`e511aff`](https://github.com/gesinn-it-pub/IDProvider/commit/e511aff)

### Changed

- Bumped `mediawiki/mediawiki-codesniffer` from 45.0.0 to 48.0.0, the highest version
  compatible with the CI container's PHP 8.1, resolving new `ClassAnnotations`,
  `CommentBeforeClass`, `PropertyDocumentation`, and `FunctionComment`/
  `ClassDocumentation` sniff violations along the way. [`b0ce277`](https://github.com/gesinn-it-pub/IDProvider/commit/b0ce277)

### Fixed

- Race condition in `IncrementIdGenerator::calculateIncrement()`: concurrent requests
  for the same prefix could read the same increment value or both insert the first row
  for a new prefix, handing out duplicate IDs. Now uses a locked atomic section
  (`SELECT ... FOR UPDATE` inside `doAtomicSection()`), backed by a new `UNIQUE` index
  on `idprovider_increments.prefix` as a defense-in-depth safety net. A schema
  migration merges any duplicate-prefix rows left over from before this fix, keeping
  the highest increment value so no previously issued ID is reused. [`fd81dbb`](https://github.com/gesinn-it-pub/IDProvider/commit/fd81dbb)
- `IdProviderFactory::dbExecute()` opened its own `LoadBalancer` via `newMainLB()`,
  which never saw the active DB domain (including MediaWiki's `unittest_` table
  prefix in tests), causing `{{#idprovider-increment}}` and the `idprovider-increment`
  API module to fail against a test database. Now uses the shared
  `getDBLoadBalancer()` connection instead. [`fd81dbb`](https://github.com/gesinn-it-pub/IDProvider/commit/fd81dbb)
- `Hooks::onUnitTestsList()` globbed `tests/phpunit/*Test.php` non-recursively, so it
  never matched any test file, since all tests live in subdirectories. [`dbaf3d9`](https://github.com/gesinn-it-pub/IDProvider/commit/dbaf3d9)
- Type mismatches and dead code surfaced by Phan (`base_convert`/`str_pad` argument
  types, nullable `$isUniqueId`, missing `$fname` argument to
  `ILoadBalancerForOwner::disable()`, unreachable pre-MW-1.36 `WikiPage::factory()`
  fallback). [`f4ab832`](https://github.com/gesinn-it-pub/IDProvider/commit/f4ab832)
- MediaWiki CodeSniffer (PHPCS) violations: missing doc comments, imprecise `object`
  typehint, non-static closures, and `__METHOD__` used inside closures instead of
  being captured outside them. `.phpcs.xml` now excludes `vendor/`, `build/`, and
  `coverage/` so build artifacts are no longer scanned. [`2879ddb`](https://github.com/gesinn-it-pub/IDProvider/commit/2879ddb)
- `FakeIdGenerator::generate()` passed the hex string from `uniqid()` to
  `base_convert()` with a base-10 source, which is invalid for hex digits `a`-`f`
  and triggered an `E_DEPRECATED` "Invalid characters passed for attempted
  conversion" warning on every call (#11). Now correctly converts from base 16. [`fdf24d8`](https://github.com/gesinn-it-pub/IDProvider/commit/fdf24d8)

### Removed

- `phpstan` and `psalm` dev dependencies, which were declared but never configured
  (superseded by Phan). [`e511aff`](https://github.com/gesinn-it-pub/IDProvider/commit/e511aff)

[Unreleased]: https://github.com/gesinn-it-pub/IDProvider/compare/3.0.1...HEAD
[3.0.1]: https://github.com/gesinn-it-pub/IDProvider/compare/3.0.0...3.0.1
[3.0.0]: https://github.com/gesinn-it-pub/IDProvider/releases/tag/3.0.0
