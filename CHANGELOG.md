# Changelog

All notable changes to `amjadiqbal/filament-xlsx-import` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- The missing-sheet message says the sheet must be visible and how `sheet()` matches (by name, or by position from 0).

### Added

- Tests: an `.xls` upload with `acceptCsv(false)` shows the "save as .xlsx" message; `sheet()` naming a hidden sheet.

## [1.0.1] - 2026-10-23

### Fixed

- With `sheet('X')`, a workbook that has no sheet X now says so ("The workbook has no sheet called \"X\"") instead of
  the generic "could not be read as a spreadsheet".
- With `acceptCsv(false)`, the browser no longer turns away `.xls` files, so the message on how to save them as `.xlsx`
  shows.

### Changed

- README: says plainly how memory and reading work for large files (request memory grows with the row count, as for
  CSV), that `headerOffset()` counts empty rows, and that the CSV example is the plugin's own, matching core's.

## [1.0.0] - 2026-10-23

First release.

### Added

- `XlsxImportAction`: Filament's `ImportAction` reading `.xlsx`, `.ods` and `.csv`, for Filament 4 and 5.
- Sheet picker for workbooks with several visible sheets; `sheet()` and `sheetPicker()`.
- Cell values as CSV-style strings: configurable `dateFormat()`, `dateTimeFormat()` and `timeFormat()`; whole numbers
  without decimals; decimals to 15 significant digits; booleans as `1`/`0`.
- Empty rows skipped, blank headings ignored, repeated headings numbered.
- `.xlsx` example download (`exampleFormat('csv')` keeps core's file); `acceptCsv()` and `acceptOds()`.
- A clear message for old `.xls` files.
- The sheet picker starts on the sheet whose headings match the Importer best.
- Workbooks are read once per import (rows buffered to a temp file); `timeLimit()` (default 300 s) for large files.

[Unreleased]: https://github.com/amjadiqbal/filament-xlsx-import/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/amjadiqbal/filament-xlsx-import/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/amjadiqbal/filament-xlsx-import/releases/tag/v1.0.0
