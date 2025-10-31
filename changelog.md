# Changelog

All notable changes to **MySQL Entity Views** will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]
### Added
- (planned) Field metadata in `__meta` views: type, required, cardinality, target entity.
- (planned) Include/Exclude field lists per bundle (UI).
- (planned) Per-field custom separators + Drush commands.

### Changed
- (planned) Performance tuning for very wide entities.

### Fixed
- (planned) Edge cases for translation-only fields.

## [1.2.0] - 2025-10-31
### Added
- Settings UI: `GROUP_CONCAT` separator, `group_concat_max_len`, ordering (`delta|value|none`).
- Optional **meta views**: `<view>__meta(column_name, comment, source)` with labels/descriptions.
- Admin CSS library and form attachment.

### Fixed
- `CREATE VIEW` uses **inlined** quoted values (no placeholders).

## [1.1.1] - 2025-10-31
### Fixed
- Completed `parseRowKey()`; attached admin CSS via library; defensive checks.

## [1.1.0] - 2025-10-31
### Changed
- Stability and DX improvements.

## [1.0.0] - 2025-10-31
### Added
- Initial release: per-bundle MySQL views for content entities (nodes/media/etc.).
