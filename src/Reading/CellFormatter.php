<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentXlsxImport\Reading;

use DateInterval;
use DateTimeInterface;

/**
 * Turns a spreadsheet cell value into the string an Importer would have received from the same column in a CSV.
 */
class CellFormatter
{
    /** Day zero of the 1900 and 1904 date systems: a time-only cell lands on one of these dates. */
    protected const BASE_DATES = ['1899-12-30', '1899-12-31', '1904-01-01'];

    public function __construct(
        public readonly string $dateFormat = 'Y-m-d',
        public readonly string $dateTimeFormat = 'Y-m-d H:i:s',
        public readonly string $timeFormat = 'H:i:s',
    ) {}

    public function format(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_string($value) => $value,
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => $this->formatFloat($value),
            $value instanceof DateTimeInterface => $this->formatDate($value),
            $value instanceof DateInterval => $this->formatInterval($value),
            default => (string) $value,
        };
    }

    protected function formatFloat(float $value): string
    {
        if (! is_finite($value)) {
            return '';
        }

        if (floor($value) === $value && abs($value) < 1e15) {
            return number_format($value, 0, '.', '');
        }

        // 15 significant digits is what Excel itself shows, so 0.1 + 0.2 reads as 0.3, not 0.30000000000000004.
        $formatted = sprintf('%.15G', $value);

        if (str_contains($formatted, 'E')) {
            $formatted = abs($value) >= 1 ? number_format($value, 0, '.', '') : rtrim(sprintf('%.20F', $value), '0');
        }

        return $formatted === '-0' ? '0' : $formatted;
    }

    protected function formatDate(DateTimeInterface $value): string
    {
        $hasTime = $value->format('H:i:s.u') !== '00:00:00.000000';

        if ($hasTime && in_array($value->format('Y-m-d'), self::BASE_DATES, true)) {
            return $value->format($this->timeFormat);
        }

        return $value->format($hasTime ? $this->dateTimeFormat : $this->dateFormat);
    }

    protected function formatInterval(DateInterval $value): string
    {
        $hours = ((int) $value->days ?: $value->d) * 24 + $value->h;

        return ($value->invert ? '-' : '').sprintf('%02d:%02d:%02d', $hours, $value->i, $value->s);
    }
}
