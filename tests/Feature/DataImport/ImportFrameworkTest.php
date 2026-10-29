<?php

use App\Models\ImportBatch;
use Database\Seeders\CurrencySeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => (new CurrencySeeder)->run());

function clientsHeader(): array
{
    return ['Código', 'Razón social', 'Tipo ID', 'RNC/Cédula', 'Tipo contribuyente', 'Moneda', 'Días de crédito', 'Límite de crédito'];
}

test('imports index requires an import permission', function () {
    actingAsRole('sales');
    $this->get(route('admin.imports.index'))->assertForbidden();
    actingAsRole('accounting');
    $this->get(route('admin.imports.index'))->assertOk()->assertInertia(fn ($p) => $p->component('admin/imports/index')->where('importers.0.type', 'clients')->has('batches'));
});

test('template downloads xlsx with labels and is logged', function () {
    actingAsRole('accounting');
    $response = $this->get(route('admin.imports.template', 'clients'));
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('spreadsheetml')
        ->and(Activity::inLog('imports')->where('event', 'template_downloaded')->where('properties->type', 'clients')->exists())->toBeTrue();
});

test('preview rejects a file with missing required columns', function () {
    actingAsRole('accounting');
    $file = makeSpreadsheet([['Código', 'Nombre comercial'], ['X', 'Y']]);
    $this->post(route('admin.imports.preview', 'clients'), ['file' => $file])
        ->assertSessionHasErrors(['file' => 'Faltan columnas obligatorias: Razón social, Tipo ID, Tipo contribuyente, Moneda']);
    expect(ImportBatch::count())->toBe(0);
});

test('preview rejects an empty file', function () {
    actingAsRole('accounting');
    $this->post(route('admin.imports.preview', 'clients'), ['file' => makeSpreadsheet([clientsHeader()])])->assertSessionHasErrors(['file' => 'El archivo está vacío.']);
});

test('preview confirm and cancel are logged', function () {
    actingAsRole('accounting');
    $rows = [clientsHeader(), ['', 'Nueva SRL', 'RNC', '1-31-24679-6', 'Persona jurídica', 'DOP', 30, 50000]];
    $this->post(route('admin.imports.preview', 'clients'), ['file' => makeSpreadsheet($rows)]);
    $batch = ImportBatch::latest('id')->firstOrFail();
    $this->post(route('admin.imports.confirm', $batch))->assertRedirect(route('admin.imports.show', $batch));
    $this->post(route('admin.imports.preview', 'clients'), ['file' => makeSpreadsheet($rows)]);
    $second = ImportBatch::latest('id')->firstOrFail();
    $this->delete(route('admin.imports.cancel', $second))->assertRedirect(route('admin.imports.index'))->assertSessionHas('success', 'Importación cancelada');

    $previewed = Activity::inLog('imports')->where('event', 'previewed')->where('subject_id', $batch->id)->firstOrFail();
    expect($previewed->properties['file'])->toBe('archivo.xlsx')->and($previewed->properties['total_rows'])->toBe(1)
        ->and(Activity::inLog('imports')->where('event', 'confirmed')->where('subject_id', $batch->id)->first()->properties['created'])->toBe(1)
        ->and(Activity::inLog('imports')->where('event', 'cancelled')->where('subject_id', $second->id)->exists())->toBeTrue()
        ->and($second->fresh()->status->value)->toBe('cancelled');
});
