<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentXlsxImport\Reading;

use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\ODS\Options as OdsOptions;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\ReaderInterface;
use OpenSpout\Reader\SheetInterface;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;
use ZipArchive;

/**
 * A streaming view of one .xlsx or .ods workbook: its sheets, a sheet's header row and its data rows as
 * header => string maps, the same shape core's CSV reader hands to an Importer. Rows are never held in memory.
 */
class Spreadsheet
{
    public const FORMATS = ['xlsx', 'ods'];

    public function __construct(
        protected string $path,
        protected string $format,
        protected CellFormatter $formatter = new CellFormatter,
    ) {
        if (! in_array($format, self::FORMATS, true)) {
            throw new UnreadableSpreadsheet("Unsupported spreadsheet format [{$format}].");
        }
    }

    /**
     * The visible sheets, in workbook order.
     *
     * @return array<int, string> sheet index => sheet name
     */
    public function sheets(): array
    {
        return $this->withReader(function (ReaderInterface $reader): array {
            $sheets = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                if ($this->isVisible($sheet)) {
                    $sheets[$sheet->getIndex()] = $sheet->getName();
                }
            }

            return $sheets;
        });
    }

    /**
     * The sheet the workbook opens on, if it is visible, else the first visible one.
     */
    public function defaultSheet(): ?int
    {
        return $this->withReader(function (ReaderInterface $reader): ?int {
            $first = null;

            foreach ($reader->getSheetIterator() as $sheet) {
                if (! $this->isVisible($sheet)) {
                    continue;
                }

                if ($sheet->isActive()) {
                    return $sheet->getIndex();
                }

                $first ??= $sheet->getIndex();
            }

            return $first;
        });
    }

    /**
     * Resolves a sheet given by index or name to its index.
     */
    public function resolveSheet(int|string|null $sheet): ?int
    {
        if ($sheet === null) {
            return $this->defaultSheet();
        }

        $sheets = $this->sheets();

        if (is_int($sheet) || ctype_digit($sheet)) {
            return array_key_exists((int) $sheet, $sheets) ? (int) $sheet : null;
        }

        $index = array_search($sheet, $sheets, true);

        return $index === false ? null : $index;
    }

    /**
     * The header row: blank headings are dropped, repeated ones get " (2)", " (3)"... appended.
     *
     * @return array<int, string> column position => heading
     */
    public function header(int $sheet, int $headerOffset = 0): array
    {
        return $this->withReader(function (ReaderInterface $reader) use ($sheet, $headerOffset): array {
            foreach ($this->physicalRows($reader, $sheet) as $number => $row) {
                if ($number === $headerOffset) {
                    return $this->headings($row);
                }
            }

            return [];
        });
    }

    /**
     * The data rows below the header. Rows with no value in any headed column are skipped.
     *
     * @return Generator<int, array<string, string>> spreadsheet row number (1-based) => heading => value
     */
    public function records(int $sheet, int $headerOffset = 0): Generator
    {
        $reader = $this->open();

        try {
            $headings = null;

            foreach ($this->physicalRows($reader, $sheet) as $number => $row) {
                if ($number < $headerOffset) {
                    continue;
                }

                if ($headings === null) {
                    $headings = $this->headings($row);

                    continue;
                }

                $record = [];
                $isBlank = true;

                foreach ($headings as $position => $heading) {
                    $value = $this->formatter->format($this->cellValue($row->getCellAtIndex($position)));
                    $record[$heading] = $value;
                    $isBlank = $isBlank && trim($value) === '';
                }

                if (! $isBlank) {
                    yield $number + 1 => $record;
                }
            }
        } finally {
            $reader->close();
        }
    }

    public function count(int $sheet, int $headerOffset = 0): int
    {
        return iterator_count($this->records($sheet, $headerOffset));
    }

    /**
     * @return array<int, string>
     */
    protected function headings(Row $row): array
    {
        $headings = [];
        $seen = [];

        foreach ($row->getCells() as $position => $cell) {
            $heading = trim($this->formatter->format($this->cellValue($cell)));

            if ($heading === '') {
                continue;
            }

            $key = mb_strtolower($heading);
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            $headings[$position] = $seen[$key] === 1 ? $heading : "{$heading} ({$seen[$key]})";
        }

        return $headings;
    }

    /**
     * A formula cell's last calculated value (what the spreadsheet shows), not its formula. Error cells read as empty.
     */
    protected function cellValue(?Cell $cell): mixed
    {
        return match (true) {
            $cell instanceof FormulaCell => $cell->getComputedValue(),
            $cell instanceof ErrorCell, $cell === null => null,
            default => $cell->getValue(),
        };
    }

    /**
     * Every row of the sheet including empty ones, so a header offset counts rows the way the spreadsheet numbers them.
     *
     * @return Generator<int, Row> 0-based row number => row
     */
    protected function physicalRows(ReaderInterface $reader, int $sheet): Generator
    {
        foreach ($reader->getSheetIterator() as $candidate) {
            if ($candidate->getIndex() !== $sheet) {
                continue;
            }

            $number = 0;

            foreach ($candidate->getRowIterator() as $row) {
                yield $number++ => $row;
            }

            return;
        }
    }

    protected function isVisible(SheetInterface $sheet): bool
    {
        return ! method_exists($sheet, 'isVisible') || $sheet->isVisible();
    }

    /**
     * @template T
     *
     * @param  callable(ReaderInterface): T  $callback
     * @return T
     */
    protected function withReader(callable $callback): mixed
    {
        $reader = $this->open();

        try {
            return $callback($reader);
        } finally {
            $reader->close();
        }
    }

    protected function open(): ReaderInterface
    {
        if ($this->format === 'xlsx') {
            $options = new XlsxOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new XlsxReader($options);
        } else {
            $options = new OdsOptions;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new OdsReader($options);
        }

        try {
            $reader->open($this->format === 'ods' ? $this->readableOdsPath() : $this->path);
            // Opening is lazy for some files; touching the sheet list surfaces a broken archive here, not mid-import.
            $reader->getSheetIterator()->rewind();
        } catch (Throwable $exception) {
            throw new UnreadableSpreadsheet($exception->getMessage(), previous: $exception);
        }

        return $reader;
    }

    /**
     * Works around two openspout 4.x ODS reader bugs by reading a corrected copy of the workbook:
     * - a FALSE cell (office:boolean-value="false") reads as true (fixed on openspout's 5.x branch): the value is
     *   emptied, which every openspout reads as false;
     * - a row with no cells at all crashes the reader when empty rows are kept: the row gets one empty cell.
     * Workbooks with neither are read in place. content.xml is streamed, so large workbooks stay out of memory.
     */
    protected function readableOdsPath(): string
    {
        $copy = static::odsCopyPath($this->path);

        if (is_file($copy)) {
            return $copy;
        }

        $zip = new ZipArchive;

        if ($zip->open($this->path) !== true) {
            return $this->path;
        }

        $content = $zip->getStream('content.xml');

        if ($content === false) {
            $zip->close();

            return $this->path;
        }

        $patched = tmpfile();
        $changed = false;
        $carry = '';

        while (! feof($content)) {
            $buffer = $carry.fread($content, 1 << 20);
            // Rows don't nest, so everything before the last row's start tag holds only complete rows.
            $cut = strrpos($buffer, '<table:table-row');
            $cut = ($cut === false || $cut === 0 || feof($content)) ? strlen($buffer) : $cut;
            $carry = substr($buffer, $cut);
            fwrite($patched, $this->correctOdsXml(substr($buffer, 0, $cut), $changed));
        }

        fwrite($patched, $this->correctOdsXml($carry, $changed));
        fclose($content);
        $zip->close();

        if (! $changed) {
            fclose($patched);

            return $this->path;
        }

        copy($this->path, $copy);
        $zip = new ZipArchive;
        $zip->open($copy);
        $zip->addFile(stream_get_meta_data($patched)['uri'], 'content.xml');
        $zip->close();
        fclose($patched);

        return $copy;
    }

    protected function correctOdsXml(string $xml, bool &$changed): string
    {
        $corrected = (string) preg_replace(
            ['/office:boolean-value="false"/', '#<table:table-row(\s[^>]*)?(?:/>|>\s*</table:table-row>)#'],
            ['office:boolean-value=""', '<table:table-row$1><table:table-cell/></table:table-row>'],
            $xml,
        );

        $changed = $changed || ($corrected !== $xml);

        return $corrected;
    }

    public static function odsCopyPath(string $path): string
    {
        return sys_get_temp_dir().'/xlsx-import-ods-'.sha1($path.'|'.@filesize($path).'|'.@filemtime($path)).'.ods';
    }

    /**
     * Removes the corrected copy made by readableOdsPath(), if any.
     */
    public static function forget(string $path): void
    {
        @unlink(static::odsCopyPath($path));
    }
}
