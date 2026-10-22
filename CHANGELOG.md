# Changelog

All notable changes to `amjadiqbal/filament-xlsx-import` are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project uses
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

[Unreleased]: https://github.com/amjadiqbal/filament-xlsx-import/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/amjadiqbal/filament-xlsx-import/releases/tag/v1.0.0
