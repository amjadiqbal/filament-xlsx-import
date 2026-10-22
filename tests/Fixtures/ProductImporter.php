<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentXlsxImport\Tests\Fixtures;

use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class ProductImporter extends Importer
{
    protected static ?string $model = Product::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')->requiredMapping()->rules(['required', 'max:255'])->example('Desk lamp'),
            ImportColumn::make('sku')->guess(['code', 'product code'])->example('LAMP-01'),
            ImportColumn::make('price')->numeric()->rules(['nullable', 'numeric'])->example('19.99'),
            ImportColumn::make('stock')->integer()->rules(['nullable', 'integer'])->example('12'),
            ImportColumn::make('released_on')->guess(['release date', 'released'])->rules(['nullable', 'date_format:Y-m-d'])->example('2026-01-31'),
            ImportColumn::make('opens_at')->guess(['opens'])->rules(['nullable', 'date_format:H:i:s'])->example('09:30:00'),
            ImportColumn::make('active')->boolean()->rules(['nullable', 'boolean'])->example('1'),
        ];
    }

    public function resolveRecord(): Product
    {
        return new Product;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        return "Imported {$import->successful_rows} products.";
    }
}
