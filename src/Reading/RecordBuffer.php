<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentXlsxImport\Reading;

use Generator;

/**
 * Rows read once from a workbook, kept in a temporary JSON-lines file. Starting an import needs the row count before
 * the rows are dispatched; buffering avoids parsing the workbook a second time, which is the slow part on large files.
 */
final class RecordBuffer
{
    private int $count = 0;

    private function __construct(
        private string $path,
    ) {}

    /**
     * @param  iterable<array<string, string>>  $records
     * @param  int|null  $stopAfter  stop reading once this many rows are buffered plus one, so "too many rows" is known
     *                               without reading the rest of the sheet
     */
    public static function fill(iterable $records, ?int $stopAfter = null): self
    {
        $buffer = new self((string) tempnam(sys_get_temp_dir(), 'xlsx-import-rows-'));
        $handle = fopen($buffer->path, 'wb');

        foreach ($records as $record) {
            fwrite($handle, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
            $buffer->count++;

            if (($stopAfter !== null) && ($buffer->count > $stopAfter)) {
                break;
            }
        }

        fclose($handle);

        return $buffer;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * @return Generator<int, array<string, string>>
     */
    public function records(): Generator
    {
        $handle = fopen($this->path, 'rb');

        try {
            while (($line = fgets($handle)) !== false) {
                yield json_decode($line, true);
            }
        } finally {
            fclose($handle);
        }
    }

    public function delete(): void
    {
        @unlink($this->path);
    }

    public function __destruct()
    {
        $this->delete();
    }
}
