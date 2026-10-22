<?php

declare(strict_types=1);

namespace AmjadIqbal\FilamentXlsxImport\Actions;

use AmjadIqbal\FilamentXlsxImport\Reading\CellFormatter;
use AmjadIqbal\FilamentXlsxImport\Reading\RecordBuffer;
use AmjadIqbal\FilamentXlsxImport\Reading\Spreadsheet;
use AmjadIqbal\FilamentXlsxImport\Reading\UnreadableSpreadsheet;
use AmjadIqbal\FilamentXlsxImport\Rules\AcceptedExtension;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ImportAction;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\ImportDispatcher;
use Filament\Actions\Imports\Models\Import;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\ChunkIterator;
use Generator;
use Illuminate\Support\Arr;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Csv\Reader as CsvReader;
use League\Csv\Statement;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Filament's ImportAction, reading .xlsx and .ods workbooks as well as .csv files. Everything after the file is
 * read (the Importer, its jobs and queue, notifications, failed-rows download) is core's own code, untouched.
 */
class XlsxImportAction extends ImportAction
{
    public const CSV_MIME_TYPES = ['text/csv', 'text/x-csv', 'application/csv', 'application/x-csv', 'text/comma-separated-values', 'text/x-comma-separated-values', 'text/plain', 'application/vnd.ms-excel'];

    public const XLSX_MIME_TYPES = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];

    public const ODS_MIME_TYPES = ['application/vnd.oasis.opendocument.spreadsheet'];

    /** Some systems report a workbook as a plain zip archive; the extension check below still applies. */
    public const ZIP_MIME_TYPES = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];

    /**
     * Old binary .xls workbooks, let past the mime check only so the extension rule can say how to convert them
     * instead of failing with a list of mime types.
     */
    public const XLS_MIME_TYPES = ['application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'];

    protected bool|Closure $acceptsCsv = true;

    protected bool|Closure $acceptsOds = true;

    protected int|string|Closure|null $sheet = null;

    protected bool|Closure $hasSheetPicker = true;

    protected string|Closure $dateFormat = 'Y-m-d';

    protected string|Closure $dateTimeFormat = 'Y-m-d H:i:s';

    protected string|Closure $timeFormat = 'H:i:s';

    protected string|Closure $exampleFormat = 'xlsx';

    protected int|Closure|null $timeLimit = 300;

    /** @var array<string, string> local copies of uploads on remote disks */
    protected array $localCopies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema(fn (XlsxImportAction $action): array => array_merge([
            FileUpload::make('file')
                ->label(__('filament-actions::import.modal.form.file.label'))
                ->placeholder(fn (): string => __('filament-xlsx-import::xlsx-import.file.placeholder', ['types' => $action->getAcceptedExtensionsLabel()]))
                ->acceptedFileTypes(fn (): array => $action->getAcceptedMimeTypes())
                ->rules($action->getFileValidationRules())
                ->afterStateUpdated(function (FileUpload $component, Component $livewire, Set $set, ?TemporaryUploadedFile $state) use ($action): void {
                    if (! $state instanceof TemporaryUploadedFile) {
                        return;
                    }

                    try {
                        $livewire->validateOnly($component->getStatePath());
                    } catch (ValidationException $exception) {
                        $component->state([]);

                        throw $exception;
                    }

                    try {
                        $sheet = $action->getDefaultSheetFor($state);
                        $headings = $action->getHeadingsFor($state, $sheet);
                    } catch (UnreadableSpreadsheet) {
                        $component->state([]);

                        throw ValidationException::withMessages([
                            $component->getStatePath() => __('filament-xlsx-import::xlsx-import.file.unreadable'),
                        ]);
                    }

                    $set('sheet', $sheet);
                    $set('columnMap', $action->guessColumnMap($headings));
                })
                ->storeFiles(false)
                ->visibility('private')
                ->required()
                ->hiddenLabel(),
            Select::make('sheet')
                ->label(__('filament-xlsx-import::xlsx-import.sheet.label'))
                ->options(fn (Get $get): array => $action->getSheetOptionsFor($get('file')))
                ->visible(fn (Get $get): bool => $action->hasSheetPicker() && (count($action->getSheetOptionsFor($get('file'))) > 1))
                ->required(fn (Get $get): bool => $action->hasSheetPicker() && (count($action->getSheetOptionsFor($get('file'))) > 1))
                ->selectablePlaceholder(false)
                ->native(false)
                ->live()
                ->afterStateUpdated(function (Get $get, Set $set, mixed $state) use ($action): void {
                    $file = $get('file');

                    if (! $file instanceof TemporaryUploadedFile) {
                        return;
                    }

                    $set('columnMap', $action->guessColumnMap($action->getHeadingsFor($file, $action->normalizeSheet($state))));
                }),
            Fieldset::make(__('filament-actions::import.modal.form.columns.label'))
                ->columns(1)
                ->inlineLabel()
                ->schema(function (Get $get) use ($action): array {
                    $file = $get('file');

                    if (! $file instanceof TemporaryUploadedFile) {
                        return [];
                    }

                    try {
                        $headings = $action->getHeadingsFor($file, $action->normalizeSheet($get('sheet')) ?? $action->getDefaultSheetFor($file));
                    } catch (UnreadableSpreadsheet) {
                        return [];
                    }

                    $options = array_combine($headings, $headings);

                    return array_map(
                        fn (ImportColumn $column): Select => $column->getSelect()->options($options),
                        $action->getImporter()::getColumns(),
                    );
                })
                ->statePath('columnMap')
                ->visible(fn (Get $get): bool => $get('file') instanceof TemporaryUploadedFile),
        ], $action->getImporter()::getOptionsFormComponents()));

        $this->action(function (XlsxImportAction $action, array $data): void {
            /** @var TemporaryUploadedFile $file */
            $file = $data['file'];

            $buffer = null;

            try {
                $sheet = $action->normalizeSheet($data['sheet'] ?? null) ?? $action->getDefaultSheetFor($file);

                if ($action->isSpreadsheet($file)) {
                    $action->extendTimeLimit();

                    // One pass over the workbook: rows are buffered while they are counted, then dispatched from the buffer.
                    $buffer = RecordBuffer::fill($action->getRecordsFor($file, $sheet), stopAfter: $action->getMaxRows());
                    $totalRows = $buffer->count();
                    $records = $buffer->records();
                } else {
                    $totalRows = $action->countRowsFor($file, $sheet);
                    $records = $action->getRecordsFor($file, $sheet);
                }
            } catch (UnreadableSpreadsheet) {
                $action->failureNotification(
                    Notification::make()
                        ->title(__('filament-xlsx-import::xlsx-import.file.unreadable'))
                        ->danger(),
                );

                $action->failure();

                return;
            }

            $maxRows = $action->getMaxRows() ?? $totalRows;

            if ($maxRows < $totalRows) {
                $buffer?->delete();

                $action->failureNotification(
                    Notification::make()
                        ->title(__('filament-actions::import.notifications.max_rows.title'))
                        ->body(trans_choice('filament-actions::import.notifications.max_rows.body', $maxRows, [
                            'count' => Number::format($maxRows),
                        ]))
                        ->danger(),
                );

                $action->failure();

                return;
            }

            $authGuard = $action->getAuthGuard();

            $import = app(Import::class);
            $import->user()->associate(auth($authGuard)->user());
            $import->file_name = $file->getClientOriginalName();
            $import->file_path = $file->getRealPath();
            $import->importer = $action->getImporter();
            $import->total_rows = $totalRows;
            $import->save();

            /** @var Generator<int, array<array<string, string>>> $importChunks */
            $importChunks = (new ChunkIterator($records, chunkSize: $action->getChunkSize()))->get();

            $options = array_merge(
                $action->getOptions(),
                Arr::except($data, ['file', 'sheet', 'columnMap']),
            );

            $jobConnection = app(ImportDispatcher::class)->dispatch(
                import: $import,
                importChunks: $importChunks,
                columnMap: $data['columnMap'],
                options: $options,
                job: $action->getJob(),
                authGuard: $authGuard,
            );

            if ($action->isSpreadsheet($file)) {
                Spreadsheet::forget($action->getLocalPath($file));
            }

            $buffer?->delete();
            $action->forgetLocalCopies();

            if (
                ($jobConnection === 'sync')
                || (blank($jobConnection) && (config('queue.default') === 'sync'))
            ) {
                $action->successNotification(null);
                $action->successNotificationTitle(null);

                return;
            }

            $action->successNotification(
                Notification::make()
                    ->title($action->getSuccessNotificationTitle())
                    ->body(trans_choice('filament-actions::import.notifications.started.body', $import->total_rows, [
                        'count' => Number::format($import->total_rows),
                    ]))
                    ->success(),
            );
        });

        $this->registerModalActions([
            Action::make('downloadExample')
                ->label(fn (): string => $this->getExampleFormat() === 'xlsx'
                    ? __('filament-xlsx-import::xlsx-import.example.label')
                    : __('filament-actions::import.modal.actions.download_example.label'))
                ->link()
                ->action(fn (): BinaryFileResponse|StreamedResponse => $this->getExampleFormat() === 'xlsx'
                    ? $this->downloadExampleWorkbook()
                    : $this->downloadExampleCsv()),
        ]);
    }

    public function acceptCsv(bool|Closure $condition = true): static
    {
        $this->acceptsCsv = $condition;

        return $this;
    }

    public function acceptOds(bool|Closure $condition = true): static
    {
        $this->acceptsOds = $condition;

        return $this;
    }

    /**
     * Always import this sheet (index from 0, or name) and hide the sheet picker.
     */
    public function sheet(int|string|Closure|null $sheet): static
    {
        $this->sheet = $sheet;

        return $this;
    }

    public function sheetPicker(bool|Closure $condition = true): static
    {
        $this->hasSheetPicker = $condition;

        return $this;
    }

    public function dateFormat(string|Closure $format): static
    {
        $this->dateFormat = $format;

        return $this;
    }

    public function dateTimeFormat(string|Closure $format): static
    {
        $this->dateTimeFormat = $format;

        return $this;
    }

    public function timeFormat(string|Closure $format): static
    {
        $this->timeFormat = $format;

        return $this;
    }

    /**
     * The format of the "Download example" file: 'xlsx' (default) or 'csv' (core's file).
     */
    public function exampleFormat(string|Closure $format): static
    {
        $this->exampleFormat = $format;

        return $this;
    }

    /**
     * Seconds the import request may run while it reads a workbook (default 300), applied only when PHP's own limit
     * is lower. Reading a large .xlsx takes far longer than reading a CSV of the same size. Null leaves PHP's limit alone.
     */
    public function timeLimit(int|Closure|null $seconds): static
    {
        $this->timeLimit = $seconds;

        return $this;
    }

    public function getTimeLimit(): ?int
    {
        return $this->evaluate($this->timeLimit);
    }

    public function extendTimeLimit(): void
    {
        $seconds = $this->getTimeLimit();
        $current = (int) ini_get('max_execution_time');

        if (($seconds !== null) && ($current !== 0) && ($current < $seconds)) {
            set_time_limit($seconds);
        }
    }

    public function acceptsCsv(): bool
    {
        return (bool) $this->evaluate($this->acceptsCsv);
    }

    public function acceptsOds(): bool
    {
        return (bool) $this->evaluate($this->acceptsOds);
    }

    public function getSheet(): int|string|null
    {
        return $this->evaluate($this->sheet);
    }

    public function hasSheetPicker(): bool
    {
        return ($this->getSheet() === null) && $this->evaluate($this->hasSheetPicker);
    }

    public function getDateFormat(): string
    {
        return $this->evaluate($this->dateFormat);
    }

    public function getDateTimeFormat(): string
    {
        return $this->evaluate($this->dateTimeFormat);
    }

    public function getTimeFormat(): string
    {
        return $this->evaluate($this->timeFormat);
    }

    public function getExampleFormat(): string
    {
        return $this->evaluate($this->exampleFormat) === 'csv' ? 'csv' : 'xlsx';
    }

    /**
     * @return array<string>
     */
    public function getAcceptedExtensions(): array
    {
        return array_values(array_filter([
            'xlsx',
            $this->acceptsOds() ? 'ods' : null,
            $this->acceptsCsv() ? 'csv' : null,
            $this->acceptsCsv() ? 'txt' : null,
        ]));
    }

    public function getAcceptedExtensionsLabel(): string
    {
        return collect($this->getAcceptedExtensions())
            ->reject(fn (string $extension): bool => $extension === 'txt')
            ->map(fn (string $extension): string => ".{$extension}")
            ->implode(', ');
    }

    /**
     * @return array<string>
     */
    public function getAcceptedMimeTypes(): array
    {
        return array_values(array_unique([
            ...self::XLSX_MIME_TYPES,
            ...($this->acceptsOds() ? self::ODS_MIME_TYPES : []),
            ...self::ZIP_MIME_TYPES,
            ...self::XLS_MIME_TYPES,
            ...($this->acceptsCsv() ? self::CSV_MIME_TYPES : []),
        ]));
    }

    /**
     * Core's rules start with `extensions:csv,txt` and a duplicate-heading check that parses the file as CSV. The
     * extension rule is swapped for ours; the CSV check still runs, on CSV files only (workbooks number repeats).
     *
     * @return array<mixed>
     */
    public function getFileValidationRules(): array
    {
        $rules = parent::getFileValidationRules();

        if (($rules[0] ?? null) === 'extensions:csv,txt') {
            array_shift($rules);
            $csvHeadingRule = array_shift($rules);

            array_unshift($rules, fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($csvHeadingRule): void {
                if (($value instanceof TemporaryUploadedFile) && ($this->getFileFormat($value) === 'csv')) {
                    $this->evaluate($csvHeadingRule)($attribute, $value, $fail);
                }
            });
        }

        array_unshift($rules, $this->getExtensionRule());

        return $rules;
    }

    public function getExtensionRule(): AcceptedExtension
    {
        return new AcceptedExtension($this->getAcceptedExtensions(), $this->getAcceptedExtensionsLabel());
    }

    /**
     * 'xlsx', 'ods' or 'csv' (anything that is not a workbook is read the way core reads it).
     */
    public function getFileFormat(TemporaryUploadedFile $file): string
    {
        $extension = Str::lower($file->getClientOriginalExtension());

        return in_array($extension, Spreadsheet::FORMATS, true) ? $extension : 'csv';
    }

    public function isSpreadsheet(mixed $file): bool
    {
        return ($file instanceof TemporaryUploadedFile) && ($this->getFileFormat($file) !== 'csv');
    }

    public function makeSpreadsheet(TemporaryUploadedFile $file): Spreadsheet
    {
        return new Spreadsheet($this->getLocalPath($file), $this->getFileFormat($file), new CellFormatter(
            dateFormat: $this->getDateFormat(),
            dateTimeFormat: $this->getDateTimeFormat(),
            timeFormat: $this->getTimeFormat(),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function getSheetOptionsFor(mixed $file): array
    {
        if (! $this->isSpreadsheet($file)) {
            return [];
        }

        try {
            return $this->makeSpreadsheet($file)->sheets();
        } catch (UnreadableSpreadsheet) {
            return [];
        }
    }

    /**
     * The sheet to read when none was picked: the configured one; else the visible sheet whose headings match the
     * most Importer columns (so a cover or notes sheet is passed over), preferring the sheet the workbook opens on
     * when there is a tie. Null for a CSV file.
     */
    public function getDefaultSheetFor(TemporaryUploadedFile $file): ?int
    {
        if (! $this->isSpreadsheet($file)) {
            return null;
        }

        $spreadsheet = $this->makeSpreadsheet($file);
        $sheet = $spreadsheet->resolveSheet($this->getSheet());

        if ($sheet === null) {
            throw new UnreadableSpreadsheet(($this->getSheet() !== null)
                ? __('filament-xlsx-import::xlsx-import.sheet.missing', ['sheet' => $this->getSheet()])
                : 'The workbook has no visible sheet.');
        }

        if ($this->getSheet() !== null) {
            return $sheet;
        }

        $candidates = array_unique([$sheet, ...array_keys($spreadsheet->sheets())]);

        if (count($candidates) < 2) {
            return $sheet;
        }

        $best = $sheet;
        $bestScore = 0;

        foreach ($candidates as $candidate) {
            $headings = array_values($spreadsheet->header($candidate, $this->getHeaderOffset() ?? 0));
            $score = count(array_filter($this->guessColumnMap($headings)));

            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public function normalizeSheet(mixed $sheet): ?int
    {
        if ($this->getSheet() !== null) {
            return null;
        }

        return (is_int($sheet) || (is_string($sheet) && ctype_digit($sheet))) ? (int) $sheet : null;
    }

    /**
     * @return array<string>
     */
    public function getHeadingsFor(TemporaryUploadedFile $file, ?int $sheet): array
    {
        if (! $this->isSpreadsheet($file)) {
            return $this->makeCsvReader($file)?->getHeader() ?? [];
        }

        $sheet ??= $this->getDefaultSheetFor($file);

        return array_values($this->makeSpreadsheet($file)->header($sheet, $this->getHeaderOffset() ?? 0));
    }

    public function countRowsFor(TemporaryUploadedFile $file, ?int $sheet): int
    {
        if (! $this->isSpreadsheet($file)) {
            $reader = $this->makeCsvReader($file);

            return $reader ? (new Statement)->process($reader)->count() : 0;
        }

        return $this->makeSpreadsheet($file)->count($sheet ?? $this->getDefaultSheetFor($file), $this->getHeaderOffset() ?? 0);
    }

    /**
     * @return iterable<array<string, string|null>>
     */
    public function getRecordsFor(TemporaryUploadedFile $file, ?int $sheet): iterable
    {
        if (! $this->isSpreadsheet($file)) {
            $reader = $this->makeCsvReader($file);

            return $reader ? (new Statement)->process($reader)->getRecords() : [];
        }

        return $this->makeSpreadsheet($file)->records($sheet ?? $this->getDefaultSheetFor($file), $this->getHeaderOffset() ?? 0);
    }

    /**
     * Matches headings to Importer columns by each column's guesses, case-insensitively, as core does for CSV.
     *
     * @param  array<string>  $headings
     * @return array<string, string|null>
     */
    public function guessColumnMap(array $headings): array
    {
        $lowercaseHeadings = array_combine(array_map(Str::lower(...), $headings), $headings);

        $columnMap = [];

        foreach ($this->getImporter()::getColumns() as $column) {
            $guess = Arr::first(array_intersect(array_keys($lowercaseHeadings), $column->getGuesses()));

            $columnMap[$column->getName()] = ($guess !== null) ? $lowercaseHeadings[$guess] : null;
        }

        return $columnMap;
    }

    /**
     * Core's CSV reading, unchanged: encoding detection, delimiter guessing and header offset.
     */
    protected function makeCsvReader(TemporaryUploadedFile $file): ?CsvReader
    {
        $stream = $this->getUploadedFileStream($file);

        if (! $stream) {
            return null;
        }

        $reader = CsvReader::from($stream);

        if (filled($delimiter = $this->getCsvDelimiter($reader))) {
            $reader->setDelimiter($delimiter);
        }

        $reader->setHeaderOffset($this->getHeaderOffset() ?? 0);

        return $reader;
    }

    /**
     * openspout needs a seekable local file (a workbook is a zip archive). Uploads on the local disk are read in
     * place; uploads on S3 or another remote disk are copied to a temporary file once per request.
     */
    public function getLocalPath(TemporaryUploadedFile $file): string
    {
        $realPath = $file->getRealPath();

        if (($realPath !== '') && is_file($realPath)) {
            return $realPath;
        }

        // One copy per upload, so the several requests of one modal (upload, sheet change, submit) share it.
        $path = sys_get_temp_dir().'/xlsx-import-'.sha1($file->getFilename()).'.'.$this->getFileFormat($file);

        if (is_file($path)) {
            return $this->localCopies[$path] = $path;
        }

        $stream = $file->readStream();

        if (! is_resource($stream)) {
            throw new UnreadableSpreadsheet('The uploaded file could not be opened.');
        }

        $target = fopen($path, 'wb');

        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);

        return $this->localCopies[$path] = $path;
    }

    public function forgetLocalCopies(): void
    {
        foreach ($this->localCopies as $path) {
            @unlink($path);
        }

        $this->localCopies = [];
    }

    /**
     * @return array<array<string>>
     */
    protected function getExampleRows(): array
    {
        $columns = $this->getImporter()::getColumns();

        $examples = array_map(fn (ImportColumn $column): array => $column->getExamples(), $columns);
        $count = array_reduce($examples, fn (int $count, array $values): int => max($count, count($values)), 0);

        $rows = [array_map(fn (ImportColumn $column): string => $column->getExampleHeader(), $columns)];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = array_map(fn (array $values): string => (string) ($values[$i] ?? ''), $examples);
        }

        return $rows;
    }

    protected function getExampleFileName(string $extension): string
    {
        $name = __('filament-actions::import.example_csv.file_name', ['importer' => (string) str($this->getImporter())->classBasename()->kebab()]);

        return Str::beforeLast($name, '.').'.'.$extension;
    }

    protected function downloadExampleWorkbook(): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-import-example-').'.xlsx';

        $writer = new XlsxWriter;
        $writer->openToFile($path);

        foreach ($this->getExampleRows() as $index => $values) {
            $writer->addRow(new Row(
                array_map(fn (string $value): Cell => Cell::fromValue($value), $values),
                $index === 0 ? (new Style)->setFontBold() : null,
            ));
        }

        $writer->close();

        return response()
            ->download($path, $this->getExampleFileName('xlsx'), [
                'Content-Type' => self::XLSX_MIME_TYPES[0],
            ])
            ->deleteFileAfterSend();
    }

    protected function downloadExampleCsv(): StreamedResponse
    {
        $rows = $this->getExampleRows();
        $delimiter = $this->getCsvDelimiter() ?: ',';

        return response()->streamDownload(function () use ($rows, $delimiter): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");

            foreach ($rows as $row) {
                fputcsv($output, $row, $delimiter, escape: '');
            }

            fclose($output);
        }, $this->getExampleFileName('csv'), [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
