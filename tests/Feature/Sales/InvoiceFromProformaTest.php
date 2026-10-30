<?php

use App\Actions\Sales\CancelInvoice;
use App\Actions\Sales\CreateInvoiceFromProforma;
use App\Actions\Sales\IssueInvoice;
use App\Enums\InvoiceStatus;
use App\Enums\ProformaStatus;
use App\Enums\StockMovementType;
use App\Exceptions\DocumentStateException;
use App\Models\Invoice;
use App\Models\NcfSequence;
use App\Models\Proforma;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryService;
use App\Support\Inventory\StockLine;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    Warehouse::factory()->create(['is_default' => true]);
});

function stockAndIssue(Invoice $draft, User $actor): Invoice
{
    foreach ($draft->lines as $line) {
        app(InventoryService::class)->receive(new StockLine($line->product_id, (int) $draft->warehouse_id, '100', '50'), StockMovementType::Initial, $actor, $actor);
    }
    if (! NcfSequence::query()->where('is_active', true)->exists()) {
        activeSequence('B01', ['range_to' => 1000]);
    }

    return app(IssueInvoice::class)->handle($draft, $actor);
}

test('an approved proforma converts into drafts and is marked invoiced only when they are issued', function () {
    $user = actingAsRole('owner');
    $p = Proforma::factory()->approved()->withLines(2)->create();
    [$l1, $l2] = $p->lines->all();

    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '1', $l2->id => '0']])->assertRedirect(route('sales.invoices.edit', Invoice::first()))->assertSessionHas('success', 'Borrador de factura creado desde '.$p->number);
    $inv = Invoice::firstOrFail();
    expect($inv->proforma_id)->toBe($p->id)->and($inv->lines()->count())->toBe(1)->and($inv->lines->first()->proforma_line_id)->toBe($l1->id)->and($inv->total)->toBe('118.00')
        ->and($l1->fresh()->invoiced_quantity)->toBe('0.0000')->and($l1->fresh()->pendingQuantity())->toBe('0.0000')->and($p->fresh()->status)->toBe(ProformaStatus::Approved)
        ->and(Activity::inLog('invoices')->where('event', 'created_from_proforma')->where('subject_id', $inv->id)->exists())->toBeTrue();

    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '1', $l2->id => '1']])->assertSessionHasErrors(['quantities.'.$l1->id => 'Cantidad pendiente: 0.0000.']);
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '0', $l2->id => '1']])->assertRedirect();
    expect(Invoice::count())->toBe(2)->and($p->fresh()->status)->toBe(ProformaStatus::Approved);

    Invoice::query()->orderBy('id')->get()->each(fn (Invoice $draft) => stockAndIssue($draft, $user));
    expect($l1->fresh()->invoiced_quantity)->toBe('1.0000')->and($l2->fresh()->invoiced_quantity)->toBe('1.0000')
        ->and($p->fresh()->status)->toBe(ProformaStatus::Invoiced)->and($p->fresh()->invoiced_at)->not->toBeNull()
        ->and(Activity::inLog('proformas')->where('event', 'invoiced')->where('subject_id', $p->id)->exists())->toBeTrue();
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$l1->id => '0', $l2->id => '0']])->assertSessionHasErrors(['quantities' => 'Indica al menos una cantidad a facturar.']);
});

test('deleting a proforma draft frees its quantities and cancelling the issued invoice reopens the proforma', function () {
    $user = actingAsRole('owner');
    $p = Proforma::factory()->approved()->withLines(1)->create();
    $line = $p->lines->first();
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$line->id => '1']])->assertRedirect();
    $draft = Invoice::firstOrFail();
    expect($line->fresh()->pendingQuantity())->toBe('0.0000');
    $this->delete(route('sales.invoices.destroy', $draft))->assertRedirect(route('sales.invoices.index'));
    expect($line->fresh()->pendingQuantity())->toBe('1.0000')->and($p->fresh()->status)->toBe(ProformaStatus::Approved);

    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$line->id => '1']])->assertRedirect();
    $issued = stockAndIssue(Invoice::latest('id')->firstOrFail(), $user);
    expect($line->fresh()->invoiced_quantity)->toBe('1.0000')->and($p->fresh()->status)->toBe(ProformaStatus::Invoiced);

    app(CancelInvoice::class)->handle($issued, $user, 'Error');
    expect($line->fresh()->invoiced_quantity)->toBe('0.0000')->and($line->fresh()->pendingQuantity())->toBe('1.0000')
        ->and($p->fresh()->status)->toBe(ProformaStatus::Approved)->and($p->fresh()->invoiced_at)->toBeNull()
        ->and(Activity::inLog('proformas')->where('event', 'reopened')->where('subject_id', $p->id)->exists())->toBeTrue();
});

test('editing a draft created from a proforma keeps the line link and respects the pending quantity', function () {
    actingAsRole('owner');
    $p = Proforma::factory()->approved()->withLines(1)->create();
    $line = $p->lines->first(); // quantity 1.0000
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$line->id => '1']])->assertRedirect();
    $draft = Invoice::firstOrFail();
    $payload = validInvoicePayload(['client_id' => $draft->client_id, 'salesperson_id' => $draft->salesperson_id, 'warehouse_id' => $draft->warehouse_id, 'lines' => [['product_id' => $line->product_id, 'description' => '', 'quantity' => '2', 'unit_price' => '100', 'discount_percent' => '0']]]);
    $this->put(route('sales.invoices.update', $draft), $payload)->assertSessionHasErrors(['lines.0.quantity' => 'Cantidad pendiente en la proforma: 1.0000.']);
    $this->put(route('sales.invoices.update', $draft), [...$payload, 'lines' => [['product_id' => $line->product_id, 'description' => '', 'quantity' => '1', 'unit_price' => '90', 'discount_percent' => '0']]])->assertRedirect(route('sales.invoices.show', $draft));
    expect($draft->fresh()->lines->first()->proforma_line_id)->toBe($line->id)->and($draft->fresh()->total)->toBe('106.20');
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

test('issuing refuses a draft whose proforma pending quantity was consumed meanwhile', function () {
    $user = actingAsRole('owner');
    activeSequence('B01', ['range_to' => 1000]);
    $p = Proforma::factory()->approved()->withLines(1)->create();
    $line = $p->lines->first();
    $this->post(route('sales.proformas.invoice', $p), ['quantities' => [$line->id => '1']])->assertRedirect();
    $draft = Invoice::firstOrFail();
    foreach ($draft->lines as $draftLine) {
        app(InventoryService::class)->receive(new StockLine($draftLine->product_id, (int) $draft->warehouse_id, '100', '50'), StockMovementType::Initial, $user, $user);
    }
    $line->forceFill(['invoiced_quantity' => $line->quantity])->save(); // another invoice issued meanwhile

    $this->post(route('sales.invoices.issue', $draft), [])->assertRedirect()
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'solo tiene pendiente'));
    expect($draft->fresh()->issued_at)->toBeNull()->and($draft->fresh()->status)->toBe(InvoiceStatus::Draft);
});
