<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentXlsxImport\Tests;

use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\ODS\Writer as OdsWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Writes real .xlsx / .ods test files with openspout. A cell given as ['value', 'format'] gets that number format.
 */
class Workbook
{
    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets  sheet name => rows (null row = empty row)
     * @param  array<string>  $hidden  sheet names to hide
     */
    public static function write(array $sheets, string $format = 'xlsx', array $hidden = []): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-import-test-').'.'.$format;
        $writer = $format === 'ods' ? new OdsWriter : new XlsxWriter;
        $writer->openToFile($path);

        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName((string) $name);
            $first = false;

            if (in_array((string) $name, $hidden, true)) {
                $sheet->setIsVisible(false);
            }

            foreach ($rows as $values) {
                $writer->addRow(new Row(array_map(self::cell(...), $values ?? [])));
            }
        }

        $writer->close();

        return $path;
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     * @param  array<string>  $hidden
     */
    public static function upload(string $name, array $sheets, array $hidden = []): UploadedFile
    {
        $format = pathinfo($name, PATHINFO_EXTENSION);

        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents(self::write($sheets, $format, $hidden)));
    }

    protected static function cell(mixed $value): Cell
    {
        if (is_array($value)) {
            return Cell::fromValue($value[0], (new Style)->setFormat($value[1]));
        }

        if ($value instanceof \DateTimeInterface) {
            return Cell::fromValue($value, (new Style)->setFormat('yyyy-mm-dd'));
        }

        return Cell::fromValue($value);
    }
}
