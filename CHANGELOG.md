# Changelog

## [1.1.0.0] - 2026-05-26

### Changed
- ORM now uses prepared statements for all write operations (insert, update, delete)
- `set_filter()` accepts an optional second parameter for value binding with `?` placeholders
- Controllers migrated from `real_escape_string()` to `set_filter([], [params])` — zero manual escaping
