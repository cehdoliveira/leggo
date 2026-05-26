# Changelog

## [1.1.0.0] - 2026-05-26



## [1.3.0.1] - 2026-05-26

### Fixed
- Replace deprecated `utf8_decode()` with `mb_convert_encoding()` for PHP 8.4 compatibility
- Zero `utf8_decode` or `utf8_encode` calls remain in codebase

## [1.3.0.0] - 2026-05-26

### Added
- Test isolation via DBTestCase with automatic transaction rollback
- 5 new DB-dependent tests
- PHPStan static analysis at level 3
- Docker volumes for tests/ and config files

### Changed
- Test bootstrap supports test helper autoloading
- Updated README with PHPStan, prepared statements, and test conventions
## [1.2.0.0] - 2026-05-26

### Added
- PHPStan static analysis at level 3 (`php app/inc/lib/vendor/bin/phpstan analyse`)
- `@method` annotations on rootOBJ and DOLModel for all magic methods
### Changed
- ORM now uses prepared statements for all write operations (insert, update, delete)
- `set_filter()` accepts an optional second parameter for value binding with `?` placeholders
- Controllers migrated from `real_escape_string()` to `set_filter([], [params])` — zero manual escaping
