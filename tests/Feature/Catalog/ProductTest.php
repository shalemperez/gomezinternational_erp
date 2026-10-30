<?php

use App\Models\AdditionalTax;
use App\Models\Brand;
use App\Models\Product;
use Spatie\Activitylog\Models\Activity;

crudResourceTests([
    'label' => 'products', 'routePrefix' => 'catalog.products', 'component' => 'catalog/products/index', 'model' => Product::class, 'table' => 'products', 'routeParam' => 'product',
    'allowedRole' => 'owner', 'deniedIndexRole' => null, 'deniedStoreRole' => 'sales',
    'payload' => fn () => validProductPayload(), 'required' => ['sku', 'name', 'unit_of_measure_id', 'units_per_box', 'itbis_rate'], 'assertColumn' => 'sku',
    'update' => ['field' => 'name', 'value' => 'Producto editado'], 'exportTable' => 'products',
    'created' => 'Producto creado', 'updated' => 'Producto actualizado', 'deleted' => 'Producto eliminado',
]);

test('store creates product with additional taxes and liter tax requires volume', function () {
    actingAsRole('owner');
    $isc = AdditionalTax::factory()->create(['calculation_type' => 'fixed_per_liter', 'rate' => null, 'amount' => '10.0000']);
    $base = validProductPayload(['sku' => 'ron-001', 'name' => 'Ron añejo 750 ml', 'units_per_box' => 6, 'additional_tax_ids' => [$isc->id]]);

    $this->post(route('catalog.products.store'), $base)->assertSessionHasErrors(['additional_tax_ids' => 'Los impuestos por litro requieren el volumen en litros del producto.']);
    $this->post(route('catalog.products.store'), array_merge($base, ['volume_liters' => '0.75', 'tracks_lots' => 'on']))->assertRedirect(route('catalog.products.index'))->assertSessionHas('success', 'Producto creado');

    $p = Product::where('sku', 'RON-001')->firstOrFail();
    expect($p->additionalTaxes->pluck('id')->all())->toBe([$isc->id])->and($p->tracks_lots)->toBeTrue()->and($p->volume_liters)->toBe('0.7500')
        ->and(Activity::inLog('products')->where('event', 'additional_taxes_synced')->where('subject_id', $p->id)->latest('id')->first()->properties['after'])->toBe([$isc->code]);
});

test('degree tax requires alcohol degree', function () {
    actingAsRole('owner');
    $isc = AdditionalTax::factory()->create(['calculation_type' => 'fixed_per_degree_liter', 'rate' => null, 'amount' => '600']);
    $this->post(route('catalog.products.store'), validProductPayload(['volume_liters' => '0.75', 'additional_tax_ids' => [$isc->id]]))
        ->assertSessionHasErrors(['additional_tax_ids' => 'Los impuestos por grado alcohólico requieren el grado alcohólico del producto.']);
});

test('index filters by brand and sales and warehouse can view but not create', function () {
    $brand = Brand::factory()->create();
    Product::factory()->create(['brand_id' => $brand->id]);
    Product::factory()->create();

    actingAsRole('sales');
    $this->get(route('catalog.products.index', ['brand_id' => $brand->id]))->assertOk()->assertInertia(fn ($p) => $p->where('rows.total', 1));
    $this->get(route('catalog.products.create'))->assertForbidden();

    actingAsRole('warehouse');
    $this->get(route('catalog.products.index'))->assertOk()->assertInertia(fn ($p) => $p->where('rows.total', 2));
    $this->post(route('catalog.products.store'), validProductPayload())->assertForbidden();
});

test('numeric fields are bounded with spanish errors instead of database errors', function () {
    actingAsRole('owner');
    $this->post(route('catalog.products.store'), validProductPayload(['weight_kg' => '99999999', 'volume_liters' => '1000000', 'minimum_stock' => '99999999999', 'alcohol_degree' => '1.234']))
        ->assertSessionHasErrors(['weight_kg', 'volume_liters', 'minimum_stock', 'alcohol_degree']);
    $this->post(route('catalog.products.store'), validProductPayload(['weight_kg' => '999999.9999', 'volume_liters' => '0.75', 'minimum_stock' => '9999999999.9999', 'alcohol_degree' => '37.5']))
        ->assertRedirect(route('catalog.products.index'));
});
