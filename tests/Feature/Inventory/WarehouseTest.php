<?php

use App\Models\Warehouse;
use Database\Seeders\WarehouseSeeder;

crudResourceTests([
    'label' => 'warehouses', 'routePrefix' => 'inventory.warehouses', 'component' => 'inventory/warehouses/index', 'model' => Warehouse::class, 'table' => 'warehouses', 'routeParam' => 'warehouse',
    'allowedRole' => 'owner', 'deniedIndexRole' => 'sales', 'deniedStoreRole' => 'warehouse',
    'payload' => fn () => validWarehousePayload(), 'required' => ['code', 'name'], 'assertColumn' => 'code',
    'update' => ['field' => 'name', 'value' => 'Almacén editado'], 'exportTable' => 'warehouses',
    'created' => 'Almacén creado', 'updated' => 'Almacén actualizado', 'deleted' => 'Almacén eliminado',
]);

test('only one default warehouse', function () {
    actingAsRole('owner');
    $this->post(route('inventory.warehouses.store'), validWarehousePayload(['code' => 'A', 'is_default' => 'on']));
    $this->post(route('inventory.warehouses.store'), validWarehousePayload(['code' => 'B', 'is_default' => 'on']));
    expect(Warehouse::where('code', 'A')->first()->is_default)->toBeFalse()->and(Warehouse::default()->code)->toBe('B');
});

test('default warehouse cannot be deactivated', function () {
    actingAsRole('owner');
    $w = Warehouse::factory()->create(['is_default' => true]);
    $this->put(route('inventory.warehouses.update', $w), validWarehousePayload(['code' => $w->code, 'is_default' => 'on', 'is_active' => '']))
        ->assertSessionHasErrors(['is_active' => 'El almacén predeterminado no se puede desactivar.']);
});

test('seeder creates the principal warehouse', function () {
    (new WarehouseSeeder)->run();
    (new WarehouseSeeder)->run();
    expect(Warehouse::count())->toBe(1)->and(Warehouse::default()->code)->toBe('PRINCIPAL');
});

test('warehouse role cannot export warehouses', function () {
    actingAsRole('warehouse');
    $this->get(route('inventory.warehouses.export', ['format' => 'csv']))->assertForbidden();
});

test('the default warehouse cannot be deleted', function () {
    actingAsRole('owner');
    $w = Warehouse::factory()->create(['is_default' => true]);
    $this->delete(route('inventory.warehouses.destroy', $w))->assertSessionHas('error', 'No se puede eliminar el almacén predeterminado.');
    expect(Warehouse::query()->whereKey($w->id)->exists())->toBeTrue();
});

test('the default flag cannot be removed by editing the default warehouse', function () {
    actingAsRole('owner');
    $w = Warehouse::factory()->create(['is_default' => true]);
    $this->put(route('inventory.warehouses.update', $w), validWarehousePayload(['code' => $w->code, 'is_default' => '']))
        ->assertSessionHasErrors(['is_default' => 'Debe existir un almacén predeterminado: marca otro almacén como predeterminado.']);
});

test('a non default warehouse can be deactivated', function () {
    actingAsRole('owner');
    $w = Warehouse::factory()->create(['is_default' => false]);
    $this->put(route('inventory.warehouses.update', $w), validWarehousePayload(['code' => $w->code, 'is_default' => '', 'is_active' => '']))->assertRedirect();
    expect($w->fresh()->is_active)->toBeFalse();
});
