<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentXlsxImport\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Checks the upload's own extension: mime sniffing alone can't tell a workbook from any other zip archive.
 */
class AcceptedExtension implements ValidationRule
{
    /**
     * @param  array<string>  $extensions
     */
    public function __construct(
        protected array $extensions,
        protected string $label,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof TemporaryUploadedFile) {
            return;
        }

        $extension = Str::lower($value->getClientOriginalExtension());

        if ($extension === 'xls') {
            $fail(__('filament-xlsx-import::xlsx-import.file.xls'));

            return;
        }

        if (! in_array($extension, $this->extensions, true)) {
            $fail(__('filament-xlsx-import::xlsx-import.file.type', ['types' => $this->label]));
        }
    }
}
