<?php

declare(strict_types=1);

use AmjadIqbal\FilamentXlsxImport\Actions\XlsxImportAction;
use AmjadIqbal\FilamentXlsxImport\Reading\RecordBuffer;
use AmjadIqbal\FilamentXlsxImport\Reading\Spreadsheet;
use AmjadIqbal\FilamentXlsxImport\Tests\Fixtures\ListProducts;
use AmjadIqbal\FilamentXlsxImport\Tests\Fixtures\Product;
use AmjadIqbal\FilamentXlsxImport\Tests\Fixtures\ProductImporter;
use AmjadIqbal\FilamentXlsxImport\Tests\Workbook;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    // Keep fake upload paths short enough for the `imports.file_path` column.
    Storage::set('tmp-for-tests', Storage::build([
        'driver' => 'local',
        'root' => sys_get_temp_dir().'/xlsx-import-'.bin2hex(random_bytes(6)),
    ]));

    ListProducts::$configureAction = null;
    $this->actingAs($this->makeUser());
});

function productsSheet(): array
{
    return [
        ['Name', 'SKU', 'Price', 'Stock', 'Release date', 'Opens', 'Active'],
        ['Desk lamp', 'LAMP-01', 19.99, 12, new DateTimeImmutable('2026-01-31'), [0.395833333333333, 'hh:mm:ss'], true],
        ['Chair', 'CH-2', 0.1 + 0.2, 3, [46000, 'yyyy-mm-dd'], null, false],
        ['Bulk paper', 1234567890123, 1500, 0, null, null, null],
    ];
}

it('imports an .xlsx file through the core Importer with guessed columns', function (): void {
    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => Workbook::upload('products.xlsx', ['Products' => productsSheet()])])
        ->assertActionDataSet([
            'columnMap' => [
                'name' => 'Name', 'sku' => 'SKU', 'price' => 'Price', 'stock' => 'Stock',
                'released_on' => 'Release date', 'opens_at' => 'Opens', 'active' => 'Active',
            ],
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Product::query()->orderBy('id')->get()->map->only(['name', 'sku', 'price', 'stock', 'released_on', 'opens_at', 'active'])->all())->toBe([
        ['name' => 'Desk lamp', 'sku' => 'LAMP-01', 'price' => '19.99', 'stock' => 12, 'released_on' => '2026-01-31', 'opens_at' => '09:30:00', 'active' => true],
        ['name' => 'Chair', 'sku' => 'CH-2', 'price' => '0.3', 'stock' => 3, 'released_on' => '2025-12-09', 'opens_at' => null, 'active' => false],
        ['name' => 'Bulk paper', 'sku' => '1234567890123', 'price' => '1500', 'stock' => 0, 'released_on' => null, 'opens_at' => null, 'active' => null],
    ]);

    $import = Import::query()->sole();
    expect($import->file_name)->toBe('products.xlsx')
        ->and($import->total_rows)->toBe(3)
        ->and($import->successful_rows)->toBe(3)
        ->and($import->importer)->toBe(ProductImporter::class);
});

it('imports an .ods file', function (): void {
    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => Workbook::upload('products.ods', ['Products' => [
            ['Name', 'Price', 'Release date', 'Opens', 'Active'],
            ['Desk lamp', 19.99, new DateTimeImmutable('2026-01-31'), new DateInterval('PT9H30M'), true],
            ['Chair', 0.1 + 0.2, null, null, false],
            ['Bulk paper', 1500, null, null, null],
        ]])])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Product::query()->orderBy('id')->get()->map->only(['name', 'price', 'released_on', 'opens_at', 'active'])->all())->toBe([
        ['name' => 'Desk lamp', 'price' => '19.99', 'released_on' => '2026-01-31', 'opens_at' => '09:30:00', 'active' => true],
        ['name' => 'Chair', 'price' => '0.3', 'released_on' => null, 'opens_at' => null, 'active' => false],
        ['name' => 'Bulk paper', 'price' => '1500', 'released_on' => null, 'opens_at' => null, 'active' => null],
    ]);
});

it('still imports .csv files the way core does', function (): void {
    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => UploadedFile::fake()->createWithContent('products.csv', "name;price\nLamp;9.5\nDesk;120\n")])
        ->assertActionDataSet(['columnMap.name' => 'name', 'columnMap.price' => 'price', 'sheet' => null])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Product::query()->pluck('price', 'name')->all())->toBe(['Lamp' => '9.5', 'Desk' => '120']);
});

it('offers a sheet picker, starts on the sheet that matches the Importer and re-guesses columns on change', function (): void {
    $file = Workbook::upload('catalog.xlsx', [
        'Notes' => [['Read me'], ['Prices in USD']],
        'Hidden' => [['Name'], ['Secret']],
        '2026' => [['Product name', 'Code'], ['Lamp', 'L-1'], ['Desk', 'D-1']],
    ], hidden: ['Hidden']);

    $component = livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => $file])
        ->assertActionDataSet(['sheet' => 2, 'columnMap.sku' => 'Code']);

    $component
        ->setActionData(['sheet' => 0])
        ->assertActionDataSet(['columnMap.sku' => null])
        ->setActionData(['sheet' => 2])
        ->assertActionDataSet(['columnMap.sku' => 'Code'])
        ->setActionData(['columnMap' => ['name' => 'Product name', 'sku' => 'Code']])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Product::query()->pluck('sku', 'name')->all())->toBe(['Lamp' => 'L-1', 'Desk' => 'D-1']);
});

it('starts on the sheet the workbook opens on when no sheet matches better', function (): void {
    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => Workbook::upload('two.xlsx', ['First' => [['Name'], ['A']], 'Second' => [['Name'], ['B']]])])
        ->assertActionDataSet(['sheet' => 0, 'columnMap.name' => 'Name']);
});

it('lists only visible sheets', function (): void {
    $path = Workbook::write([
        'One' => [['a']],
        'Hidden' => [['b']],
        'Three' => [['c']],
    ], hidden: ['Hidden']);

    $spreadsheet = new Spreadsheet($path, 'xlsx');

    expect($spreadsheet->sheets())->toBe([0 => 'One', 2 => 'Three'])
        ->and($spreadsheet->resolveSheet('Three'))->toBe(2)
        ->and($spreadsheet->resolveSheet('Hidden'))->toBeNull()
        ->and($spreadsheet->resolveSheet(1))->toBeNull();
});

it('always reads a configured sheet and hides the picker', function (): void {
    ListProducts::$configureAction = fn (XlsxImportAction $action) => $action->sheet('Stock');

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => Workbook::upload('catalog.xlsx', [
            'Intro' => [['Hello']],
            'Stock' => [['Name', 'Stock'], ['Lamp', 4]],
        ])])
        ->assertActionDataSet(['columnMap.name' => 'Name', 'columnMap.stock' => 'Stock'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Product::query()->pluck('stock', 'name')->all())->toBe(['Lamp' => 4]);
});

it('rejects a workbook without the configured sheet', function (): void {
    ListProducts::$configureAction = fn (XlsxImportAction $action) => $action->sheet('Stock');

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => Workbook::upload('catalog.xlsx', ['Other' => [['Name'], ['Lamp']]])])
        ->assertHasActionErrors(['file']);

    expect(Product::query()->count())->toBe(0);
});

it('skips empty rows, drops blank headings and numbers repeated ones', function (string $format): void {
    $path = Workbook::write(format: $format, sheets: ['Data' => [
        ['Name', '', 'Name', 'Stock'],
        ['Lamp', 'ignored', 'Lamp B', 4],
        null,
        ['', '', '', ''],
        ['Desk', null, null, 2],
        null,
    ]]);

    $spreadsheet = new Spreadsheet($path, $format);

    expect($spreadsheet->header(0))->toBe([0 => 'Name', 2 => 'Name (2)', 3 => 'Stock'])
        ->and(iterator_to_array($spreadsheet->records(0)))->toBe([
            2 => ['Name' => 'Lamp', 'Name (2)' => 'Lamp B', 'Stock' => '4'],
            5 => ['Name' => 'Desk', 'Name (2)' => '', 'Stock' => '2'],
        ])
        ->and($spreadsheet->count(0))->toBe(2);
})->with(['xlsx', 'ods']);

it('reads a formula as its calculated value and an error cell as empty, as Excel saves them', function (): void {
    $spreadsheet = new Spreadsheet(__DIR__.'/../files/excel-style.xlsx', 'xlsx');
    $records = iterator_to_array($spreadsheet->records(1));

    expect($spreadsheet->sheets())->toBe([0 => 'Read me', 1 => '2026 catalog'])
        ->and($records[2])->toMatchArray(['Product name' => 'Desk lamp', 'Price' => '19.99', 'Stock value' => '239.88', 'Check' => ''])
        ->and($records[3])->toMatchArray(['Stock value' => '358', 'Check' => 'ok'])
        ->and($records[9]['Code'])->toBe('00123');
});

it('reads FALSE in an .ods file as false', function (): void {
    $spreadsheet = new Spreadsheet(Workbook::write(['S' => [['A', 'B'], [true, false]]], 'ods'), 'ods');

    expect(iterator_to_array($spreadsheet->records(0), false))->toBe([['A' => '1', 'B' => '0']]);
});

it('honours the header offset as a spreadsheet row index', function (): void {
    ListProducts::$configureAction = fn (XlsxImportAction $action) => $action->headerOffset(2);

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => Workbook::upload('report.xlsx', ['Report' => [
            ['Stock report'],
            null,
            ['Name', 'Stock'],
            ['Lamp', 7],
        ]])])
        ->assertActionDataSet(['columnMap.name' => 'Name'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Product::query()->pluck('stock', 'name')->all())->toBe(['Lamp' => 7]);
});

it('uses the configured date formats', function (): void {
    ListProducts::$configureAction = fn (XlsxImportAction $action) => $action->dateFormat('d/m/Y');

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => Workbook::upload('products.xlsx', ['P' => [['Name', 'SKU'], ['Lamp', new DateTimeImmutable('2026-03-04')]]])])
        ->callMountedAction();

    expect(Product::query()->value('sku'))->toBe('04/03/2026');
});

it('refuses old .xls workbooks with a clear message', function (): void {
    $component = livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => UploadedFile::fake()->createWithContent('legacy.xls', (string) file_get_contents(__DIR__.'/../files/legacy.xls'))])
        ->assertHasActionErrors(['file']);

    expect($component->errors()->first('mountedActions.0.data.file'))->toBe(__('filament-xlsx-import::xlsx-import.file.xls'));

    expect(Import::query()->count())->toBe(0);
});

it('refuses a broken .xlsx file', function (): void {
    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => UploadedFile::fake()->createWithContent('broken.xlsx', 'PK this is not a workbook')])
        ->assertHasActionErrors(['file']);

    expect(Import::query()->count())->toBe(0);
});

it('can stop accepting .csv files', function (): void {
    ListProducts::$configureAction = fn (XlsxImportAction $action) => $action->acceptCsv(false);

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => UploadedFile::fake()->createWithContent('products.csv', "name\nLamp\n")])
        ->assertHasActionErrors(['file']);
});

it('enforces maxRows on the rows that are not empty', function (): void {
    ListProducts::$configureAction = fn (XlsxImportAction $action) => $action->maxRows(2);

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => Workbook::upload('products.xlsx', ['P' => [['Name'], ['A'], null, ['B'], ['C']]])])
        ->callMountedAction()
        ->assertNotified(__('filament-actions::import.notifications.max_rows.title'));

    expect(Product::query()->count())->toBe(0)
        ->and(Import::query()->count())->toBe(0);
});

it('reads the workbook once and stops reading once maxRows is exceeded', function (): void {
    $buffer = RecordBuffer::fill((function () {
        foreach (range(1, 100) as $i) {
            yield ['n' => (string) $i];
        }
        throw new RuntimeException('read past the limit');
    })(), stopAfter: 3);

    expect($buffer->count())->toBe(4)
        ->and(iterator_to_array($buffer->records(), false))->toBe([['n' => '1'], ['n' => '2'], ['n' => '3'], ['n' => '4']]);

    $all = RecordBuffer::fill([['a' => 'é ✓ "quoted"'], ['a' => "two\nlines"]]);
    expect(iterator_to_array($all->records(), false))->toBe([['a' => 'é ✓ "quoted"'], ['a' => "two\nlines"]]);
});

it('raises a lower PHP time limit while reading a workbook, and only then', function (): void {
    $action = XlsxImportAction::make()->importer(ProductImporter::class);
    $before = ini_get('max_execution_time');

    try {
        set_time_limit(30);
        $action->extendTimeLimit();
        expect(ini_get('max_execution_time'))->toBe('300');

        set_time_limit(900);
        $action->extendTimeLimit();
        expect(ini_get('max_execution_time'))->toBe('900');

        set_time_limit(30);
        $action->timeLimit(null)->extendTimeLimit();
        expect(ini_get('max_execution_time'))->toBe('30');
    } finally {
        set_time_limit((int) $before);
    }
});

it('reports failed rows through core like a CSV import', function (): void {
    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => Workbook::upload('products.xlsx', ['P' => [['Name', 'Price'], ['Lamp', 5], ['', 7.5]]])])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $import = Import::query()->sole();

    expect($import->successful_rows)->toBe(1)
        ->and($import->getFailedRowsCount())->toBe(1)
        ->and($import->failedRows()->sole()->data)->toBe(['Name' => '', 'Price' => '7.5']);
});

it('queues the import in chunks on a real queue, like core', function (): void {
    config()->set('queue.default', 'database');
    ListProducts::$configureAction = fn (XlsxImportAction $action) => $action->chunkSize(2);

    $rows = [['Name', 'Stock']];
    foreach (range(1, 5) as $i) {
        $rows[] = ["Item {$i}", $i];
    }

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => Workbook::upload('products.xlsx', ['P' => $rows])])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified(__('filament-actions::import.notifications.started.title'));

    expect(Product::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(3);

    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true]);

    expect(Product::query()->count())->toBe(5)
        ->and(Import::query()->sole()->completed_at)->not->toBeNull()
        ->and(DB::table('notifications')->count())->toBe(1);
});

it('streams a large workbook without loading it into memory', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'big-').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Name', 'SKU', 'Price', 'Stock']));
    for ($i = 1; $i <= 30000; $i++) {
        $writer->addRow(Row::fromValues(["Product {$i}", "SKU-{$i}", $i / 100, $i]));
    }
    $writer->close();

    $spreadsheet = new Spreadsheet($path, 'xlsx');
    $before = memory_get_usage();
    $seen = 0;
    $last = null;

    foreach ($spreadsheet->records(0) as $record) {
        $seen++;
        $last = $record;
    }

    expect($seen)->toBe(30000)
        ->and($last)->toBe(['Name' => 'Product 30000', 'SKU' => 'SKU-30000', 'Price' => '300', 'Stock' => '30000'])
        ->and(memory_get_usage() - $before)->toBeLessThan(8 * 1024 * 1024);
});

it('imports a large workbook end to end', function (): void {
    ListProducts::$configureAction = fn (XlsxImportAction $action) => $action->chunkSize(500);

    $rows = [['Name', 'Stock']];
    for ($i = 1; $i <= 5000; $i++) {
        $rows[] = ["Item {$i}", $i];
    }

    livewire(ListProducts::class)
        ->mountAction('import')
        ->setActionData(['file' => Workbook::upload('big.xlsx', ['P' => $rows])])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(Product::query()->count())->toBe(5000)
        ->and(Import::query()->sole()->successful_rows)->toBe(5000);
});

it('downloads an example .xlsx built from the Importer columns', function (): void {
    $action = XlsxImportAction::make()->importer(ProductImporter::class);
    $response = (fn () => $this->downloadExampleWorkbook())->call($action);

    expect($response)->toBeInstanceOf(BinaryFileResponse::class)
        ->and($response->headers->get('content-disposition'))->toContain('product-importer-example.xlsx');

    $spreadsheet = new Spreadsheet($response->getFile()->getPathname(), 'xlsx');

    expect(array_values($spreadsheet->header(0)))->toBe(['name', 'sku', 'price', 'stock', 'released_on', 'opens_at', 'active'])
        ->and(iterator_to_array($spreadsheet->records(0), false))->toBe([[
            'name' => 'Desk lamp', 'sku' => 'LAMP-01', 'price' => '19.99', 'stock' => '12',
            'released_on' => '2026-01-31', 'opens_at' => '09:30:00', 'active' => '1',
        ]]);
});

it('can keep core\'s CSV example file', function (): void {
    $action = XlsxImportAction::make()->importer(ProductImporter::class)->exampleFormat('csv');
    $response = (fn () => $this->downloadExampleCsv())->call($action);

    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toBe("\xEF\xBB\xBFname,sku,price,stock,released_on,opens_at,active\n\"Desk lamp\",LAMP-01,19.99,12,2026-01-31,09:30:00,1\n");
});
