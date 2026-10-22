<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentXlsxImport\Tests\Fixtures;

use AmjadIqbal\FilamentXlsxImport\Actions\XlsxImportAction;
use Closure;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    /** @var (Closure(XlsxImportAction): mixed)|null set by a test to configure the action */
    public static ?Closure $configureAction = null;

    protected function getHeaderActions(): array
    {
        $action = XlsxImportAction::make()->importer(ProductImporter::class);

        if (static::$configureAction) {
            (static::$configureAction)($action);
        }

        return [$action];
    }
}
