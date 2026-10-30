<?php

use App\Exceptions\ImmutableDocumentException;
use App\Models\AccountingEvent;
use App\Models\Client;
use App\Models\ImportBatch;
use App\Models\Invoice;
use App\Models\Salesperson;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('historical invoices import validates rows and registers immutable open invoices', function () {
    actingAsRole('accounting');
    $sp = Salesperson::factory()->create(['code' => 'VEN-01']);
    $withSp = Client::factory()->create(['tax_id' => '101850043', 'salesperson_id' => $sp->id, 'credit_days' => 30]);
    $noSp = Client::factory()->create(['code' => 'CLI-00099', 'salesperson_id' => null]);
    $file = makeSpreadsheet([
        ['NCF', 'Cliente (RNC, cédula o código)', 'Vendedor (código)', 'Fecha (AAAA-MM-DD)', 'Vencimiento (AAAA-MM-DD)', 'Moneda', 'Base imponible', 'ITBIS', 'Total', 'Pagado', 'Notas'],
        ['B0100000123', '1-01-85004-3', '', '2026-09-15', '', 'DOP', '10000', '1800', '11800', '1800', 'Saldo abierto'],
        ['B0200000005', 'CLI-00099', 'VEN-01', '2026-09-20', '2026-10-20', 'DOP', '500', '90', '590', '', ''],
        ['B0200000006', 'CLI-00099', '', '2026-09-21', '', 'DOP', '500', '90', '590', '', ''],
        ['B0100000124', '1-01-85004-3', '', '2026-09-22', '', 'DOP', '500', '90', '500', '', ''],
        ['B0100000123', '1-01-85004-3', '', '2026-09-23', '', 'DOP', '1', '0', '1', '', ''],
        ['X0100000001', '9-99-99999-9', '', '2026-13-01', '', 'EUR', '-1', 'x', '0', '5', ''],
    ]);
    $this->post(route('admin.imports.preview', 'historical_invoices'), ['file' => $file])->assertRedirect();
    $batch = ImportBatch::latest('id')->firstOrFail();
    expect($batch->valid_rows)->toBe(2)->and($batch->invalid_rows)->toBe(4)
        ->and($batch->rows()->where('row_number', 4)->first()->errors['salesperson_code'])->toBe('El cliente no tiene vendedor asignado; indica el código del vendedor.')
        ->and($batch->rows()->where('row_number', 5)->first()->errors['total'])->toBe('El total no puede ser menor que la base imponible más el ITBIS.')
        ->and($batch->rows()->where('row_number', 6)->first()->errors)->toHaveKey('_row')
        ->and($batch->rows()->where('row_number', 7)->first()->errors)->toHaveKeys(['ncf', 'client', 'issue_date', 'currency_code', 'taxable_total', 'itbis_total', 'total']);

    $this->post(route('admin.imports.confirm', $batch))->assertSessionHas('success', 'Importación completada: 2 creados, 0 actualizados');
    $a = Invoice::where('ncf', 'B0100000123')->firstOrFail();
    expect($a->kind->value)->toBe('historical')->and($a->fiscal_mode)->toBe('external')->and($a->number)->toBe('FAC-000001')->and($a->ncf_type->value)->toBe('B01')
        ->and($a->status->value)->toBe('partially_paid')->and($a->balance)->toBe('10000.00')->and($a->due_date->toDateString())->toBe('2026-10-15')->and($a->salesperson_id)->toBe($sp->id)
        ->and($a->client_name)->toBe($withSp->business_name)->and($a->issued_at)->not->toBeNull()->and($a->lines()->count())->toBe(0)
        ->and(AccountingEvent::where('type', 'historical_invoice_registered')->count())->toBe(2)
        ->and(Activity::inLog('invoices')->where('event', 'registered_historical')->count())->toBe(2);
    expect(fn () => $a->update(['notes' => 'x']))->toThrow(ImmutableDocumentException::class);
    expect(Invoice::where('ncf', 'B0200000005')->first()->salesperson_id)->toBe($sp->id);

    $again = makeSpreadsheet([['NCF', 'Cliente (RNC, cédula o código)', 'Fecha (AAAA-MM-DD)', 'Moneda', 'Base imponible', 'ITBIS', 'Total'], ['B0100000123', '1-01-85004-3', '2026-09-15', 'DOP', '1', '0', '1']]);
    $this->post(route('admin.imports.preview', 'historical_invoices'), ['file' => $again])->assertRedirect();
    expect(ImportBatch::latest('id')->first()->rows()->first()->errors['ncf'])->toBe('El NCF ya está registrado.');
});

test('sales users cannot use the historical importer', function () {
    actingAsRole('sales');
    $this->get(route('admin.imports.template', 'historical_invoices'))->assertForbidden();
});
