<?php

use App\Services\Settings;
use Spatie\Activitylog\Models\Activity;

test('settings round-trip scalars, arrays and null and are logged', function () {
    $s = app(Settings::class);
    $s->set('company.name', 'Gomez');
    $s->set('a.list', ['x', 'y']);
    $s->set('empty', null);

    expect($s->get('company.name'))->toBe('Gomez')
        ->and($s->get('a.list'))->toBe(['x', 'y'])
        ->and($s->get('empty', 'dflt'))->toBe('dflt')
        ->and($s->get('missing', 7))->toBe(7)
        ->and($s->many(['company.name', 'missing']))->toBe(['company.name' => 'Gomez', 'missing' => null]);

    $s->set('company.name', 'Gomez International');
    expect(app(Settings::class)->get('company.name'))->toBe('Gomez International')
        ->and(Activity::inLog('settings')->where('event', 'updated')->exists())->toBeTrue();
});
