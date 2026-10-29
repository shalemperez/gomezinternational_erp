<?php

use App\Models\AdditionalTax;

crudResourceTests([
    'label' => 'additional taxes', 'routePrefix' => 'catalog.additional-taxes', 'component' => 'catalog/additional-taxes/index', 'model' => AdditionalTax::class, 'table' => 'additional_taxes', 'routeParam' => 'additionalTax',
    'allowedRole' => 'owner', 'deniedIndexRole' => 'sales', 'deniedStoreRole' => 'warehouse',
    'payload' => fn () => validAdditionalTaxPayload(), 'required' => ['code', 'name', 'calculation_type'], 'assertColumn' => 'code',
    'update' => ['field' => 'name', 'value' => 'ISC editado'], 'exportTable' => 'additional-taxes',
    'created' => 'Impuesto adicional creado', 'updated' => 'Impuesto adicional actualizado', 'deleted' => 'Impuesto adicional eliminado',
]);

test('percent type requires rate and fixed types require amount', function () {
    actingAsRole('owner');
    $this->post(route('catalog.additional-taxes.store'), validAdditionalTaxPayload(['rate' => '']))->assertSessionHasErrors(['rate']);
    $this->post(route('catalog.additional-taxes.store'), validAdditionalTaxPayload(['calculation_type' => 'fixed_per_unit', 'rate' => '', 'amount' => '']))->assertSessionHasErrors(['amount']);
    $this->post(route('catalog.additional-taxes.store'), validAdditionalTaxPayload(['code' => 'ISC-UND', 'calculation_type' => 'fixed_per_unit', 'rate' => '', 'amount' => '25.5']))->assertRedirect(route('catalog.additional-taxes.index'));
    expect(AdditionalTax::where('code', 'ISC-UND')->first()->amount)->toBe('25.5000');
});
