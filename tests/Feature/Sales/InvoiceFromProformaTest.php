<?php

use App\Actions\Sales\CreateInvoiceFromProforma;
use App\Enums\ProformaStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Invoice;
use App\Models\Proforma;
use App\Models\Warehouse;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    Warehouse::factory()->create(['is_default' => true]);
});

test('an approved proforma converts partially then fully into draft invoices', function () {
    actingAsRole('owner');
    $p = Proforma::factory()->approved()->withLines(2)->create();
    [$l1, $l2] = $p->lines->all();

    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '1', $l2->id => '0']])->assertRedirect(route('sales.invoices.edit', Invoice::first()))->assertSessionHas('success', 'Borrador de factura creado desde '.$p->number);
    $inv = Invoice::firstOrFail();
    expect($inv->proforma_id)->toBe($p->id)->and($inv->lines()->count())->toBe(1)->and($inv->lines->first()->product_id)->toBe($l1->product_id)->and($inv->total)->toBe('118.00')
        ->and($l1->fresh()->invoiced_quantity)->toBe('1.0000')->and($p->fresh()->status)->toBe(ProformaStatus::Approved)
        ->and(Activity::inLog('invoices')->where('event', 'created_from_proforma')->where('subject_id', $inv->id)->exists())->toBeTrue();

    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '1', $l2->id => '1']])->assertSessionHasErrors(['quantities.'.$l1->id => 'Cantidad pendiente: 0.0000.']);
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '0', $l2->id => '1']])->assertRedirect();
    expect($p->fresh()->status)->toBe(ProformaStatus::Invoiced)->and($p->fresh()->invoiced_at)->not->toBeNull()->and(Invoice::count())->toBe(2)
        ->and(Activity::inLog('proformas')->where('event', 'invoiced')->where('subject_id', $p->id)->exists())->toBeTrue();
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '0', $l2->id => '0']])->assertSessionHasErrors(['quantities' => 'Indica al menos una cantidad a facturar.']);
});

test('only approved proformas can be invoiced', function () {
    actingAsRole('owner');
    $p = Proforma::factory()->sent()->withLines(1)->create();
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$p->lines->first()->id => '1']])->assertRedirect()->assertSessionHas('error', 'Solo se pueden facturar proformas aprobadas');
});

test('the conversion re-checks pending quantities under the lock', function () {
    $actor = actingAsRole('owner');
    $p = Proforma::factory()->approved()->withLines(1)->create();
    $line = $p->lines->first();
    $line->forceFill(['invoiced_quantity' => $line->quantity])->save(); // simulates a concurrent conversion that already invoiced everything

    expect(fn () => app(CreateInvoiceFromProforma::class)->handle($p, [$line->id => '1'], $actor))
        ->toThrow(DocumentStateException::class, 'Cantidad pendiente: 0.0000.');
    expect(Invoice::count())->toBe(0);
});
