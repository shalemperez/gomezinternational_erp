<?php

use Illuminate\Support\Facades\DB;

test('tests run on postgresql', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('gomez_test');
});

test('app timezone and locale are dominican', function () {
    expect(config('app.timezone'))->toBe('America/Santo_Domingo')
        ->and(config('app.locale'))->toBe('es');
});
