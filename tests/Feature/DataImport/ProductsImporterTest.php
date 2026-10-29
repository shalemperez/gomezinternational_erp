<?php

use App\Models\AdditionalTax;
use App\Models\Brand;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\UnitOfMeasure;
use Database\Seeders\CurrencySeeder;

beforeEach(fn () => (new CurrencySeeder)->run());

test('preview and confirm products with taxes brand and unit resolution', function () {
    Brand::factory()->create(['name' => 'Brugal']);
    UnitOfMeasure::factory()->create(['code' => 'UND']);
    AdditionalTax::factory()->create(['code' => 'ISC-ADV', 'calculation_type' => 'percent_of_base', 'rate' => '10']);
    $existing = Product::factory()->create(['sku' => 'OLD-1', 'name' => 'Viejo']);
    actingAsRole('accounting');
    $file = makeSpreadsheet([
        ['SKU', 'Nombre', 'Marca', 'Unidad', 'ITBIS', 'Controla lotes', 'Volumen (L)', 'Impuestos adicionales'],
        ['ron-750', 'Ron añejo', 'Brugal', 'und', '18', 'Sí', '0.75', 'ISC-ADV'],
        ['OLD-1', 'Renombrado', '', 'UND', '16', 'No', '', ''],
        ['BAD-1', 'Marca inexistente', 'Nope', 'UND', '18', '', '', ''],
        ['BAD-2', 'Impuesto inexistente', '', 'UND', '18', '', '', 'ZZZ'],
    ]);
    $this->post(route('admin.imports.preview', 'products'), ['file' => $file])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->valid_rows)->toBe(2)->and($batch->invalid_rows)->toBe(2)
        ->and($batch->rows()->where('row_number', 4)->first()->errors['brand_name'])->toBe('La marca no existe.')
        ->and($batch->rows()->where('row_number', 5)->first()->errors['additional_tax_codes'])->toBe('Impuesto adicional desconocido: ZZZ');

    $this->post(route('admin.imports.confirm', $batch))->assertSessionHas('success', 'Importación completada: 1 creados, 1 actualizados');
    $ron = Product::where('sku', 'RON-750')->firstOrFail();
    expect($ron->tracks_lots)->toBeTrue()->and($ron->itbis_rate)->toBe('18.00')->and($ron->additionalTaxes->pluck('code')->all())->toBe(['ISC-ADV'])
        ->and($existing->fresh()->name)->toBe('Renombrado')->and($existing->fresh()->itbis_rate)->toBe('16.00');
});

test('liter tax without volume is invalid', function () {
    UnitOfMeasure::factory()->create(['code' => 'UND']);
    AdditionalTax::factory()->create(['code' => 'ISC-L', 'calculation_type' => 'fixed_per_liter', 'rate' => null, 'amount' => '10']);
    actingAsRole('accounting');
    $file = makeSpreadsheet([['SKU', 'Nombre', 'Unidad', 'ITBIS', 'Impuestos adicionales'], ['X-1', 'Sin volumen', 'UND', '18', 'ISC-L']]);
    $this->post(route('admin.imports.preview', 'products'), ['file' => $file]);
    expect(ImportBatch::latest('id')->first()->rows()->first()->errors['additional_tax_codes'])->toBe('Los impuestos por litro requieren el volumen en litros del producto.');
});
