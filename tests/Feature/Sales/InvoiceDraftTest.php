<?php

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Salesperson;
use App\Models\Warehouse;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('a draft invoice is created without number or ncf and computes due date from credit days', function () {
    ['salesperson' => $sp] = salesUserWithSalesperson();
    $client = Client::factory()->create(['salesperson_id' => $sp->id, 'credit_days' => 45]);
    $this->post(route('sales.invoices.store'), validInvoicePayload(['client_id' => $client->id, 'credit_days' => 45]))->assertRedirect();
    $i = Invoice::firstOrFail();
    expect($i->status)->toBe(InvoiceStatus::Draft)->and($i->number)->toBeNull()->and($i->ncf)->toBeNull()->and($i->kind->value)->toBe('sale')
        ->and($i->due_date->toDateString())->toBe(now()->addDays(45)->toDateString())->and($i->total)->toBe('236.00')->and($i->salesperson_id)->toBe($sp->id)
        ->and(Activity::inLog('invoices')->where('event', 'created')->exists())->toBeTrue();
    $this->get(route('sales.invoices.show', $i))->assertOk()->assertInertia(fn ($p) => $p->component('sales/invoices/show')->where('invoice.status', 'draft'));
});

test('cash invoices are due on the issue date and credit days are bounded', function () {
    actingAsRole('owner');
    $sp = Salesperson::factory()->create();
    $this->post(route('sales.invoices.store'), validInvoicePayload(['salesperson_id' => $sp->id, 'payment_terms' => 'cash', 'credit_days' => 0]))->assertRedirect();
    expect(Invoice::firstOrFail()->due_date->toDateString())->toBe(now()->toDateString());
    $this->post(route('sales.invoices.store'), validInvoicePayload(['salesperson_id' => $sp->id, 'credit_days' => 400]))->assertSessionHasErrors(['credit_days']);
    $this->post(route('sales.invoices.store'), validInvoicePayload(['salesperson_id' => $sp->id, 'warehouse_id' => Warehouse::factory()->create(['is_active' => false])->id]))->assertSessionHasErrors(['warehouse_id' => 'El almacén no existe o está inactivo.']);
});

test('only drafts can be edited or deleted and scoping applies', function () {
    actingAsRole('owner');
    $sp = Salesperson::factory()->create();
    $this->post(route('sales.invoices.store'), validInvoicePayload(['salesperson_id' => $sp->id]));
    $draft = Invoice::firstOrFail();
    $this->put(route('sales.invoices.update', $draft), validInvoicePayload(['salesperson_id' => $sp->id, 'client_id' => $draft->client_id, 'lines' => [['product_id' => $draft->lines->first()->product_id, 'quantity' => '5', 'unit_price' => '10', 'discount_percent' => '0', 'description' => '']]]))->assertRedirect(route('sales.invoices.show', $draft));
    expect($draft->fresh()->total)->toBe('59.00');
    $issued = issuedInvoice();
    $this->get(route('sales.invoices.edit', $issued))->assertRedirect()->assertSessionHas('error', 'Solo se pueden editar facturas en borrador');
    $this->delete(route('sales.invoices.destroy', $issued))->assertRedirect()->assertSessionHas('error', 'Solo se pueden eliminar facturas en borrador');
    $this->delete(route('sales.invoices.destroy', $draft))->assertRedirect(route('sales.invoices.index'))->assertSessionHas('success', 'Borrador de factura eliminado');

    salesUserWithSalesperson();
    $this->get(route('sales.invoices.show', $issued))->assertForbidden();
    $this->get(route('sales.invoices.index'))->assertInertia(fn ($p) => $p->has('rows.data', 0));
});
