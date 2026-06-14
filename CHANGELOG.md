# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `releaseJob($job)` — additive method to un-reserve a just-reserved job (DB backends clear the reservation; Beanstalkd delegates to native release). Keeps the API model consistent across adapters.
- `getReadyCount(?string $pipeline = null): int` and `getBuriedCount(?string $pipeline = null): int` — lightweight additive observability helpers (support explicit pipeline or the currently selected one).
- Support for configuring the reserved-job stale timeout via top-level options key `'stale_timeout'` (in seconds). Default remains 300 (5 minutes) for full backward compatibility. Example:
  ```php
  $jq = new Job_Queue('mysql', ['stale_timeout' => 120]);
  ```

### Changed
- (Internal) `getNextJobAndReserve()` now respects the (configurable) stale timeout option while preserving original default behavior.
- Improved `tearDown()` in the test suite to clean up custom table names used by `Job_Queue` instances (best-effort, using reflection on `getSqlTableName`).

### Fixed
- (From Phase 1, carried forward) Multi custom `table_name` support, table existence detection, and Postgres schema PRIMARY KEY.

## [1.3.0] - 2026 (merged via PR #10)

### Added
- GitHub Actions CI workflow (`.github/workflows/ci.yml`) with PHP matrix (7.4/8.1/8.3), `composer validate --strict`, and test runs. This provides the requested sanity layer for future dependency work.
- `AGENTS.md` with project-specific guidelines for API stability, PHP 7.4+ support, CI usage, and non-breaking development.

### Changed
- `composer.json`:
  - Added explicit `"php": "^7.4 || ^8.0"` requirement.
  - Moved `pda/pheanstalk` to `suggest` (only required for Beanstalkd usage).
  - Updated description to accurately list all supported backends.
  - Bumped `phpunit/phpunit` dev dep to `^9.6` (latest in the 9.x series compatible with PHP 7.4).
- `phpunit.xml` and test suite minor updates for compatibility.
- README.md: Fixed all code examples (previously invalid PHP due to missing semicolons), corrected copy-paste comments, improved Testing section, added PHP 7.4+ note.

### Fixed
- Critical non-breaking internal bugs:
  - Static cache for table creation now supports multiple custom `table_name`s in the same process (per-table keys + legacy compat key).
  - Table existence checks (SHOW TABLES, pg_tables, sqlite_master) now use bare table names instead of pre-quoted identifiers.
  - Postgres `CREATE TABLE` now includes a proper `PRIMARY KEY` on the id column (only affects newly created tables).

All changes in 1.3.0 were designed to be 100% backward compatible with the existing public API and observed behavior.

## [1.2.0] - 2025-06

### Added / Changed
- Handled DB race conditions using transactions + `FOR UPDATE` (skipped for SQLite).
- Achieved 100% test coverage.
- Various cleanups and test improvements.

See git history for full details of earlier releases.

[Unreleased]: https://github.com/n0nag0n/simple-job-queue/compare/HEAD...master
[1.3.0]: https://github.com/n0nag0n/simple-job-queue/releases/tag/...
