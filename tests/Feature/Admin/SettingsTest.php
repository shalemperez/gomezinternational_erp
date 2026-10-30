<?php

use App\Services\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

function settingsPayload(array $over = []): array
{
    return array_replace_recursive([
        'company' => ['name' => 'Gomez International', 'legal_name' => 'Gomez International SRL', 'rnc' => '1-01-85004-3', 'address' => 'Av. X #1', 'phone' => '809-000-0000', 'email' => 'info@empresa.test'],
        'exchange_rates' => ['source' => 'manual', 'http_json' => ['url' => '', 'json_path' => '']],
        'fiscal' => ['consumo_id_threshold' => '250000'], 'sales' => ['proforma_validity_days' => '15'], 'inventory' => ['expiring_days' => '30'],
    ], $over);
}

test('settings page requires view permission', function () {
    actingAsRole('warehouse');
    $this->get(route('admin.settings.index'))->assertForbidden();
    actingAsRole('accounting');
    $this->get(route('admin.settings.index'))->assertOk()->assertInertia(fn ($p) => $p->component('admin/settings/index')->where('canManage', false));
});

test('owner saves company data, logo and exchange source', function () {
    Storage::fake('public');
    actingAsRole('owner');

    $this->put(route('admin.settings.update'), settingsPayload([
        'exchange_rates' => ['source' => 'http_json', 'http_json' => ['url' => 'https://rates.test/{currency}/{date}', 'json_path' => 'data.sell']],
    ]) + ['logo' => UploadedFile::fake()->image('logo.png', 300, 100)])
        ->assertRedirect(route('admin.settings.index'))->assertSessionHas('success', 'Configuración guardada');

    $s = app(Settings::class);
    expect($s->get('company.rnc'))->toBe('101850043')
        ->and($s->get('exchange_rates.source'))->toBe('http_json')
        ->and($s->get('exchange_rates.http_json.json_path'))->toBe('data.sell')
        ->and($s->get('company.logo_path'))->toStartWith('logos/');
    Storage::disk('public')->assertExists($s->get('company.logo_path'));
});

test('validation: invalid rnc and missing url for http source', function () {
    actingAsRole('owner');
    $this->put(route('admin.settings.update'), settingsPayload([
        'company' => ['rnc' => '101850042'],
        'exchange_rates' => ['source' => 'http_json'],
    ]))->assertSessionHasErrors(['company.rnc', 'exchange_rates.http_json.url', 'exchange_rates.http_json.json_path']);
});

test('accounting cannot update settings', function () {
    actingAsRole('accounting');
    $this->put(route('admin.settings.update'), settingsPayload())->assertForbidden();
});

test('settings update is logged with the changed keys', function () {
    actingAsRole('owner');
    $this->put(route('admin.settings.update'), settingsPayload())->assertRedirect(route('admin.settings.index'));

    $log = Activity::inLog('settings')->where('event', 'updated')->whereNotNull('properties->keys')->latest('id')->firstOrFail();
    expect($log->properties['keys'])->toContain('company.name');
});

test('sales and inventory settings are saved with bounds', function () {
    actingAsRole('owner');
    $this->put(route('admin.settings.update'), settingsPayload(['fiscal' => ['consumo_id_threshold' => '300000'], 'sales' => ['proforma_validity_days' => '20'], 'inventory' => ['expiring_days' => '60']]))->assertRedirect();
    $s = app(Settings::class);
    expect($s->get('fiscal.consumo_id_threshold'))->toBe('300000.00')->and($s->get('sales.proforma_validity_days'))->toBe(20)->and($s->get('inventory.expiring_days'))->toBe(60);
    $this->put(route('admin.settings.update'), settingsPayload(['fiscal' => ['consumo_id_threshold' => '-1'], 'sales' => ['proforma_validity_days' => '0'], 'inventory' => ['expiring_days' => '400']]))->assertSessionHasErrors(['fiscal.consumo_id_threshold', 'sales.proforma_validity_days', 'inventory.expiring_days']);
    $this->get(route('admin.settings.index'))->assertInertia(fn ($p) => $p->has('values', fn ($values) => $values->where('fiscal.consumo_id_threshold', '300000.00')->etc())->where('fiscalMode', 'ncf'));
});
