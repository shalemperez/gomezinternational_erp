<?php

use App\Models\Client;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Salesperson;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
});

test('a historical invoice can be registered manually and then collected', function () {
    actingAsRole('accounting');
    $client = Client::factory()->create(['salesperson_id' => Salesperson::factory()->create()->id]);
    $this->get(route('sales.invoices.historical.create'))->assertOk()->assertInertia(fn ($p) => $p->component('sales/invoices/historical-form'));
    $payload = ['ncf' => 'B0100000777', 'client_id' => $client->id, 'salesperson_id' => '', 'issue_date' => '2026-08-01', 'due_date' => '', 'currency_id' => Currency::base()->id, 'taxable_total' => '1000', 'itbis_total' => '180', 'total' => '1180', 'paid_amount' => '0', 'notes' => ''];
    $this->post(route('sales.invoices.historical.store'), $payload)->assertRedirect()->assertSessionHas('success', 'Factura histórica B0100000777 registrada');
    $i = Invoice::firstOrFail();
    expect($i->kind->value)->toBe('historical')->and($i->balance)->toBe('1180.00')->and($i->due_date->toDateString())->toBe('2026-08-31');
    $this->post(route('sales.invoices.historical.store'), $payload)->assertSessionHasErrors(['ncf' => 'El NCF ya está registrado.']);
    $this->post(route('sales.invoices.historical.store'), [...$payload, 'ncf' => 'B0300000001'])->assertSessionHasErrors(['ncf']);
    $this->post(route('sales.receipts.store'), validReceiptPayload($i))->assertRedirect();
    expect($i->fresh()->status->value)->toBe('paid');
    $this->get(route('sales.invoices.pdf', $i))->assertOk();
});

test('historical invoices appear in the index with their kind and the manual form needs the import permission', function () {
    actingAsRole('accounting');
    $client = Client::factory()->create(['salesperson_id' => Salesperson::factory()->create()->id]);
    $this->post(route('sales.invoices.historical.store'), ['ncf' => 'B0100000778', 'client_id' => $client->id, 'salesperson_id' => '', 'issue_date' => '2026-08-01', 'due_date' => '', 'currency_id' => Currency::base()->id, 'taxable_total' => '1', 'itbis_total' => '0', 'total' => '1', 'paid_amount' => '0', 'notes' => ''])->assertRedirect();
    $this->get(route('sales.invoices.index', ['kind' => 'historical']))->assertInertia(fn ($p) => $p->has('rows.data', 1)->where('rows.data.0.kind_label', 'Histórica'));
    actingAsRole('sales');
    $this->get(route('sales.invoices.historical.create'))->assertForbidden();
});
