# Changelog

## [1.1.0.0] - 2026-05-26


## [1.2.0.0] - 2026-05-26

### Added
- PHPStan static analysis at level 3 (`php app/inc/lib/vendor/bin/phpstan analyse`)
- `@method` annotations on rootOBJ and DOLModel for all magic methods
### Changed
- ORM now uses prepared statements for all write operations (insert, update, delete)
- `set_filter()` accepts an optional second parameter for value binding with `?` placeholders
- Controllers migrated from `real_escape_string()` to `set_filter([], [params])` — zero manual escaping
