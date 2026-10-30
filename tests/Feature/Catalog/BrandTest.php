<?php

use App\Models\Brand;
use Spatie\Activitylog\Models\Activity;

crudResourceTests([
    'label' => 'brands', 'routePrefix' => 'catalog.brands', 'component' => 'catalog/brands/index', 'model' => Brand::class, 'table' => 'brands', 'routeParam' => 'brand',
    'allowedRole' => 'owner', 'deniedIndexRole' => null, 'deniedStoreRole' => 'warehouse',
    'payload' => fn () => validBrandPayload(), 'required' => ['name'], 'assertColumn' => 'name',
    'update' => ['field' => 'name', 'value' => 'Marca Editada'], 'exportTable' => 'brands',
    'created' => 'Marca creada', 'updated' => 'Marca actualizada', 'deleted' => 'Marca eliminada',
]);

test('brand name must be unique', function () {
    actingAsRole('owner');
    Brand::factory()->create(['name' => 'Repetida']);
    $this->post(route('catalog.brands.store'), validBrandPayload(['name' => 'Repetida']))->assertSessionHasErrors(['name']);
});

test('deactivating a brand is logged with an explicit description', function () {
    actingAsRole('owner');
    $brand = Brand::factory()->create();
    $this->put(route('catalog.brands.update', $brand), validBrandPayload(['name' => $brand->name, 'is_active' => '0']))->assertRedirect(route('catalog.brands.index'));
    expect($brand->fresh()->is_active)->toBeFalse()
        ->and(Activity::inLog('brands')->where('subject_id', $brand->id)->latest('id')->first()->description)->toBe('Registro desactivado');
});
