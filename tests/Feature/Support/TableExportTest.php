<?php

use App\Exports\TableExport;
use App\Models\User;
use App\Support\AuditHistory;
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
