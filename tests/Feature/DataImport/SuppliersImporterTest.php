<?php

use App\Models\ImportBatch;
use App\Models\Supplier;
use Database\Seeders\CurrencySeeder;

beforeEach(fn () => (new CurrencySeeder)->run());

test('preview and confirm suppliers with create update and invalid rows', function () {
    $existing = Supplier::factory()->create(['country_code' => 'DO', 'tax_id' => '101850043', 'foreign_tax_id' => null, 'name' => 'Viejo']);
    actingAsRole('accounting');
    $file = makeSpreadsheet([
        ['Nombre', 'País', 'RNC', 'ID fiscal extranjera', 'Moneda', 'Días de crédito'],
        ['Nuevo LLC', 'US', '', 'US-1', 'USD', 60],
        ['Actualizado SRL', 'DO', '1-01-85004-3', '', 'DOP', 15],
        ['Mal país', 'ZZ', '', '', 'USD', 0],
        ['Extranjero con RNC', 'US', '131246796', '', 'USD', 0],
    ]);
    $this->post(route('admin.imports.preview', 'suppliers'), ['file' => $file])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->valid_rows)->toBe(2)->and($batch->invalid_rows)->toBe(2)
        ->and($batch->rows()->where('row_number', 4)->first()->errors)->toHaveKey('country_code')
        ->and($batch->rows()->where('row_number', 5)->first()->errors['tax_id'])->toBe('El RNC/cédula solo aplica a proveedores locales.');

    $this->post(route('admin.imports.confirm', $batch))->assertSessionHas('success', 'Importación completada: 1 creados, 1 actualizados');
    expect(Supplier::where('name', 'Nuevo LLC')->first()->code)->toMatch('/^PRV-\d{5}$/')->and($existing->fresh()->name)->toBe('Actualizado SRL');
});

test('an import never changes an existing supplier code country or foreign tax id', function () {
    $s = Supplier::factory()->create(['code' => 'PRV-90001', 'country_code' => 'US', 'foreign_tax_id' => 'US-11', 'tax_id' => null, 'name' => 'Viejo']);
    actingAsRole('accounting');
    $file = makeSpreadsheet([
        ['Código', 'Nombre', 'País', 'ID fiscal extranjera', 'Moneda'],
        ['PRV-90001', 'Nuevo Nombre', 'ES', 'ES-22', 'USD'],
    ]);
    $this->post(route('admin.imports.preview', 'suppliers'), ['file' => $file])->assertRedirect();
    $this->post(route('admin.imports.confirm', ImportBatch::latest('id')->firstOrFail()))->assertRedirect();
    $s->refresh();
    expect($s->name)->toBe('Nuevo Nombre')->and($s->code)->toBe('PRV-90001')->and($s->country_code)->toBe('US')->and($s->foreign_tax_id)->toBe('US-11');
});
