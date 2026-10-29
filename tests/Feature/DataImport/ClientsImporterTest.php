<?php

use App\Models\Client;
use App\Models\ImportBatch;
use Database\Seeders\CurrencySeeder;
use Illuminate\Http\UploadedFile;

beforeEach(fn () => (new CurrencySeeder)->run());

function clientsFile(string $ext = 'xlsx'): UploadedFile
{
    return makeSpreadsheet([
        ['Código', 'Razón social', 'Tipo ID', 'RNC/Cédula', 'Tipo contribuyente', 'Moneda', 'Días de crédito', 'Límite de crédito'],
        ['', 'Nueva SRL', 'RNC', '1-31-24679-6', 'Persona jurídica', 'DOP', 30, 50000],
        ['', 'Actualizada SRL', 'rnc', '101850043', 'Persona jurídica', 'DOP', 15, 1000.5],
        ['', 'Mala', 'RNC', '101850042', 'Persona jurídica', 'DOP', 0, 0],
        ['', 'Duplicada', 'RNC', '1-31-24679-6', 'Persona jurídica', 'DOP', 0, 0],
        ['', 'Final', 'Ninguno', '', 'Consumidor final', 'XXX', 0, 0],
    ], $ext);
}

test('preview classifies rows and confirm creates and updates', function () {
    $existing = Client::factory()->create(['tax_id' => '101850043', 'business_name' => 'Vieja']);
    actingAsRole('accounting');

    $this->post(route('admin.imports.preview', 'clients'), ['file' => clientsFile()])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->total_rows)->toBe(5)->and($batch->valid_rows)->toBe(2)->and($batch->invalid_rows)->toBe(3)
        ->and($batch->rows()->where('row_number', 2)->first()->action)->toBe('create')
        ->and($batch->rows()->where('row_number', 3)->first()->action)->toBe('update')
        ->and($batch->rows()->where('row_number', 4)->first()->errors['tax_id'])->toBe('El RNC debe tener 9 dígitos y un dígito verificador válido.')
        ->and($batch->rows()->where('row_number', 5)->first()->errors['_row'])->toBe('Valor duplicado en el archivo (fila 2)')
        ->and($batch->rows()->where('row_number', 6)->first()->errors)->toHaveKey('currency_code');

    $this->post(route('admin.imports.confirm', $batch))->assertRedirect(route('admin.imports.show', $batch))->assertSessionHas('success', 'Importación completada: 1 creados, 1 actualizados');
    expect(Client::where('tax_id', '131246796')->first()->credit_limit)->toBe('50000.00')
        ->and(Client::where('tax_id', '131246796')->first()->code)->toMatch('/^CLI-\d{5}$/')
        ->and($existing->fresh()->business_name)->toBe('Actualizada SRL')->and($existing->fresh()->credit_limit)->toBe('1000.50');
});

test('csv files are accepted', function () {
    actingAsRole('accounting');
    $this->post(route('admin.imports.preview', 'clients'), ['file' => clientsFile('csv')])->assertRedirect();
    expect(ImportBatch::latest('id')->first()->valid_rows)->toBe(2);
});

test('confirm twice is rejected', function () {
    actingAsRole('accounting');
    $this->post(route('admin.imports.preview', 'clients'), ['file' => clientsFile()]);
    $batch = ImportBatch::latest('id')->firstOrFail();
    $this->post(route('admin.imports.confirm', $batch))->assertRedirect();
    $this->post(route('admin.imports.confirm', $batch))->assertStatus(422);
});

test('an invalid rnc is never imported even for an owner', function () {
    actingAsRole('owner');
    $this->post(route('admin.imports.preview', 'clients'), ['file' => clientsFile()]);
    $row = ImportBatch::latest('id')->firstOrFail()->rows()->where('row_number', 4)->firstOrFail();
    expect($row->is_valid)->toBeFalse()->and($row->errors['tax_id'])->toBe('El RNC debe tener 9 dígitos y un dígito verificador válido.');
});
