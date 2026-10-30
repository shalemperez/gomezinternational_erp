<?php

use App\Models\Supplier;
use Spatie\Activitylog\Models\Activity;

crudResourceTests([
    'label' => 'suppliers', 'routePrefix' => 'catalog.suppliers', 'component' => 'catalog/suppliers/index', 'model' => Supplier::class, 'table' => 'suppliers', 'routeParam' => 'supplier',
    'allowedRole' => 'owner', 'deniedIndexRole' => 'sales', 'deniedStoreRole' => 'warehouse',
    'payload' => fn () => validSupplierPayload(), 'required' => ['name', 'country_code', 'currency_id', 'credit_days'], 'assertColumn' => 'name',
    'update' => ['field' => 'name', 'value' => 'Proveedor editado'], 'exportTable' => 'suppliers',
    'created' => 'Proveedor creado', 'updated' => 'Proveedor actualizado', 'deleted' => 'Proveedor eliminado',
]);

test('local supplier tax id is validated and normalised', function () {
    actingAsRole('owner');
    $this->post(route('catalog.suppliers.store'), validSupplierPayload(['country_code' => 'DO', 'foreign_tax_id' => '', 'tax_id' => '101850042']))->assertSessionHasErrors(['tax_id' => 'El RNC debe tener 9 dígitos y un dígito verificador válido.']);
    $this->post(route('catalog.suppliers.store'), validSupplierPayload(['country_code' => 'DO', 'foreign_tax_id' => '', 'tax_id' => '1-01-85004-3']))->assertRedirect(route('catalog.suppliers.index'));
    expect(Supplier::latest('id')->first()->tax_id)->toBe('101850043');
});

test('local supplier may use a cedula', function () {
    actingAsRole('owner');
    $this->post(route('catalog.suppliers.store'), validSupplierPayload(['country_code' => 'DO', 'foreign_tax_id' => '', 'tax_id' => '001-1391820-5']))->assertRedirect(route('catalog.suppliers.index'));
    expect(Supplier::latest('id')->first()->tax_id)->toBe('00113918205');
});

test('foreign supplier cannot have a dominican tax id but keeps its foreign id', function () {
    actingAsRole('owner');
    $this->post(route('catalog.suppliers.store'), validSupplierPayload(['tax_id' => '101850043']))->assertSessionHasErrors(['tax_id' => 'El RNC/cédula solo aplica a proveedores locales.']);
    $this->post(route('catalog.suppliers.store'), validSupplierPayload())->assertRedirect(route('catalog.suppliers.index'));
    $s = Supplier::latest('id')->firstOrFail();
    expect($s->tax_id)->toBeNull()->and($s->foreign_tax_id)->toBe('US-12-3456789')->and($s->is_local)->toBeFalse();
});

test('code is generated when blank', function () {
    actingAsRole('owner');
    $this->post(route('catalog.suppliers.store'), validSupplierPayload());
    expect(Supplier::latest('id')->first()->code)->toMatch('/^PRV-\d{5}$/');
});

test('a supplier created without code gets PRV code from its id and an update with blank code keeps it', function () {
    actingAsRole('owner');
    $this->post(route('catalog.suppliers.store'), validSupplierPayload(['code' => '']))->assertRedirect();
    $supplier = Supplier::query()->latest('id')->firstOrFail();
    expect($supplier->code)->toBe('PRV-'.str_pad((string) $supplier->id, 5, '0', STR_PAD_LEFT));

    $this->put(route('catalog.suppliers.update', $supplier), validSupplierPayload(['code' => '', 'name' => 'Nombre Nuevo']))->assertRedirect();
    expect($supplier->fresh()->code)->toBe('PRV-'.str_pad((string) $supplier->id, 5, '0', STR_PAD_LEFT));
});

test('supplier contacts are synced by id and only one can be primary', function () {
    actingAsRole('owner');
    $this->post(route('catalog.suppliers.store'), validSupplierPayload(['contacts' => [['name' => 'Ana', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '1'], ['name' => 'Beto', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '0']]]));
    $s = Supplier::latest('id')->firstOrFail();
    [$ana, $beto] = $s->contacts()->orderBy('id')->get()->all();
    $this->put(route('catalog.suppliers.update', $s), validSupplierPayload(['contacts' => [['id' => $ana->id, 'name' => 'Ana M', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '1']]]))->assertRedirect(route('catalog.suppliers.index'));
    expect($s->fresh()->contacts->pluck('name')->all())->toBe(['Ana M'])
        ->and(Activity::inLog('supplier_contacts')->where('event', 'updated')->where('subject_id', $ana->id)->exists())->toBeTrue()
        ->and(Activity::inLog('supplier_contacts')->where('event', 'deleted')->where('subject_id', $beto->id)->exists())->toBeTrue();
    $this->put(route('catalog.suppliers.update', $s), validSupplierPayload(['contacts' => [['name' => 'A', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '1'], ['name' => 'B', 'position' => '', 'email' => '', 'phone' => '', 'is_primary' => '1']]]))
        ->assertSessionHasErrors(['contacts' => 'Solo un contacto puede ser el principal.']);
});

test('owner can force an invalid rnc with a reason and accounting cannot', function () {
    $owner = actingAsRole('owner');
    $this->post(route('catalog.suppliers.store'), validSupplierPayload(['country_code' => 'DO', 'foreign_tax_id' => '', 'tax_id' => '101850042', 'tax_id_override' => 'on', 'tax_id_override_reason' => 'Verificado con el proveedor']))->assertRedirect(route('catalog.suppliers.index'));
    $s = Supplier::where('tax_id', '101850042')->firstOrFail();
    expect($s->tax_id_validation_overridden)->toBeTrue()->and($s->tax_id_override_by)->toBe($owner->id)
        ->and(Activity::inLog('suppliers')->where('event', 'tax_id_validation_overridden')->where('subject_id', $s->id)->exists())->toBeTrue();

    actingAsRole('accounting');
    $this->post(route('catalog.suppliers.store'), validSupplierPayload(['name' => 'Otro', 'country_code' => 'DO', 'foreign_tax_id' => '', 'tax_id' => '131246795', 'tax_id_override' => 'on', 'tax_id_override_reason' => 'x']))->assertForbidden();
});
