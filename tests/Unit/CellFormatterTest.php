<?php

declare(strict_types=1);

use AmjadIqbal\FilamentXlsxImport\Reading\CellFormatter;

it('formats cell values the way a CSV would have them', function (mixed $value, string $expected): void {
    expect((new CellFormatter)->format($value))->toBe($expected);
})->with([
    'null' => [null, ''],
    'string' => ['  Lamp ', '  Lamp '],
    'int' => [42, '42'],
    'negative int' => [-7, '-7'],
    'integral float' => [1500.0, '1500'],
    'float noise' => [0.1 + 0.2, '0.3'],
    'decimal' => [19.99, '19.99'],
    'negative zero' => [-0.0, '0'],
    'big integral float' => [1234567890123456.0, '1234567890123456'],
    'tiny float' => [0.000001234, '0.000001234'],
    'true' => [true, '1'],
    'false' => [false, '0'],
    'date' => [new DateTimeImmutable('2026-01-31'), '2026-01-31'],
    'date time' => [new DateTimeImmutable('2026-01-31 14:05:09'), '2026-01-31 14:05:09'],
    'time (1900 system)' => [new DateTimeImmutable('1899-12-30 09:30:00'), '09:30:00'],
    'time (1904 system)' => [new DateTimeImmutable('1904-01-01 18:00:00'), '18:00:00'],
    'duration' => [new DateInterval('PT36H15M'), '36:15:00'],
]);

it('uses custom date formats', function (): void {
    $formatter = new CellFormatter(dateFormat: 'd/m/Y', dateTimeFormat: 'd/m/Y H:i', timeFormat: 'g:i A');

    expect($formatter->format(new DateTimeImmutable('2026-01-31')))->toBe('31/01/2026')
        ->and($formatter->format(new DateTimeImmutable('2026-01-31 14:05')))->toBe('31/01/2026 14:05')
        ->and($formatter->format(new DateTimeImmutable('1899-12-30 14:05')))->toBe('2:05 PM');
});
