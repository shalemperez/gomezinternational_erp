<?php

use App\Models\UnitOfMeasure;
use Database\Seeders\UnitOfMeasureSeeder;

crudResourceTests([
    'label' => 'units', 'routePrefix' => 'catalog.units', 'component' => 'catalog/units/index', 'model' => UnitOfMeasure::class, 'table' => 'units_of_measure', 'routeParam' => 'unit',
    'allowedRole' => 'owner', 'deniedIndexRole' => 'sales', 'deniedStoreRole' => 'warehouse',
    'payload' => fn () => validUnitPayload(), 'required' => ['code', 'name'], 'assertColumn' => 'code',
    'update' => ['field' => 'name', 'value' => 'Docena editada'], 'exportTable' => 'units',
    'created' => 'Unidad de medida creada', 'updated' => 'Unidad de medida actualizada', 'deleted' => 'Unidad de medida eliminada',
]);

test('code is stored uppercase', function () {
    actingAsRole('owner');
    $this->post(route('catalog.units.store'), validUnitPayload(['code' => 'pal']))->assertRedirect(route('catalog.units.index'));
    $this->assertDatabaseHas('units_of_measure', ['code' => 'PAL']);
});

test('seeder creates the base units', function () {
    (new UnitOfMeasureSeeder)->run();
    (new UnitOfMeasureSeeder)->run();
    expect(UnitOfMeasure::count())->toBe(5)->and(UnitOfMeasure::where('code', 'UND')->first()->name)->toBe('Unidad');
});
