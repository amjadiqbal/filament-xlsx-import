<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentXlsxImport\Reading;

/**
 * The workbook is readable but has no sheet with the name or index given to `sheet()`.
 */
class SheetNotFound extends UnreadableSpreadsheet {}
