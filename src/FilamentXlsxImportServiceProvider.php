<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentXlsxImport;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FilamentXlsxImportServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-xlsx-import';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasTranslations();
    }
}
