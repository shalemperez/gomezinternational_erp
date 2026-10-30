<?php

use App\Actions\Sales\SaveProforma;
use App\Enums\ProformaStatus;
use App\Jobs\ExpireProformas;
use App\Models\Client;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\Proforma;
use App\Models\Salesperson;
use App\Models\SentEmail;
use Database\Seeders\CurrencySeeder;
use Database\Seeders\DocumentSequenceSeeder;
use Database\Seeders\EmailTemplateSeeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    (new CurrencySeeder)->run();
    (new DocumentSequenceSeeder)->run();
    (new EmailTemplateSeeder)->run();
});

test('a sales user creates a proforma for an own client with server-computed totals and a number', function () {
    ['salesperson' => $sp] = salesUserWithSalesperson();
    $client = Client::factory()->create(['salesperson_id' => $sp->id]);
    $other = Salesperson::factory()->create();

    $this->post(route('sales.proformas.store'), validProformaPayload(['client_id' => $client->id, 'salesperson_id' => $other->id]))
        ->assertRedirect()->assertSessionHas('success', 'Proforma COT-000001 creada');

    $p = Proforma::firstOrFail();
    expect($p->number)->toBe('COT-000001')->and($p->status)->toBe(ProformaStatus::Draft)->and($p->salesperson_id)->toBe($sp->id)
        ->and($p->subtotal)->toBe('200.00')->and($p->itbis_total)->toBe('36.00')->and($p->total)->toBe('236.00')->and($p->total_base)->toBe('236.00')
        ->and($p->exchange_rate)->toBe('1.000000')->and($p->lines()->count())->toBe(1)->and($p->lines->first()->line_total)->toBe('236.00')
        ->and(Activity::inLog('proformas')->where('event', 'created')->where('subject_id', $p->id)->exists())->toBeTrue()
        ->and(Activity::inLog('proforma_lines')->where('event', 'created')->count())->toBe(1);
});

test('inactive client, product or salesperson and foreign clients are rejected', function () {
    actingAsRole('owner');
    $inactiveClient = Client::factory()->create(['is_active' => false]);
    $inactiveProduct = Product::factory()->create(['is_active' => false]);
    $inactiveSp = Salesperson::factory()->create(['is_active' => false]);
    $this->post(route('sales.proformas.store'), validProformaPayload(['client_id' => $inactiveClient->id, 'salesperson_id' => $inactiveSp->id, 'lines' => [['product_id' => $inactiveProduct->id, 'quantity' => '1', 'unit_price' => '1', 'discount_percent' => '0', 'description' => '']]]))
        ->assertSessionHasErrors(['client_id' => 'El cliente no existe o está inactivo.', 'salesperson_id' => 'El vendedor no existe o está inactivo.', 'lines.0.product_id' => 'El producto no existe o está inactivo.']);
    $this->post(route('sales.proformas.store'), validProformaPayload(['salesperson_id' => '']))->assertSessionHasErrors(['salesperson_id' => 'Selecciona el vendedor.']);

    salesUserWithSalesperson();
    $foreign = Client::factory()->create(['salesperson_id' => Salesperson::factory()->create()->id]);
    $this->post(route('sales.proformas.store'), validProformaPayload(['client_id' => $foreign->id]))->assertSessionHasErrors(['client_id' => 'No puedes crear documentos para clientes de otros vendedores.']);
    $unlinked = actingAsRole('sales');
    expect(fn () => app(SaveProforma::class)->handle(validProformaPayload(), $unlinked))
        ->toThrow(fn (ValidationException $e) => expect($e->errors()['salesperson_id'])->toBe(['Tu usuario no está vinculado a un vendedor.']));
});

test('users without view-all only see and open their own proformas', function () {
    ['salesperson' => $mine] = salesUserWithSalesperson();
    $own = Proforma::factory()->create(['salesperson_id' => $mine->id]);
    $foreign = Proforma::factory()->create();

    $this->get(route('sales.proformas.index'))->assertOk()->assertInertia(fn ($p) => $p->component('sales/proformas/index')->has('rows.data', 1)->where('rows.data.0.id', $own->id));
    $this->get(route('sales.proformas.show', $foreign))->assertForbidden();
    $this->get(route('sales.proformas.show', $own))->assertOk()->assertInertia(fn ($p) => $p->component('sales/proformas/show')->where('proforma.number', $own->number)->has('history'));
    actingAsRole('management');
    $this->get(route('sales.proformas.index'))->assertInertia(fn ($p) => $p->has('rows.data', 2));
});

test('validation: dates, lines and numeric bounds', function () {
    actingAsRole('owner');
    $sp = Salesperson::factory()->create();
    $this->post(route('sales.proformas.store'), validProformaPayload(['salesperson_id' => $sp->id, 'valid_until' => now()->subDay()->toDateString(), 'lines' => []]))
        ->assertSessionHasErrors(['valid_until', 'lines' => 'Agrega al menos una línea.']);
    $this->post(route('sales.proformas.store'), validProformaPayload(['salesperson_id' => $sp->id, 'lines' => [['product_id' => Product::factory()->create()->id, 'quantity' => '0', 'unit_price' => '99999999999', 'discount_percent' => '101', 'description' => '']]]))
        ->assertSessionHasErrors(['lines.0.quantity' => 'La cantidad debe ser mayor que cero.', 'lines.0.unit_price', 'lines.0.discount_percent']);
});

test('usd proformas store the exchange rate of the issue date and total_base in DOP', function () {
    actingAsRole('owner');
    $sp = Salesperson::factory()->create();
    $usd = Currency::where('code', 'USD')->firstOrFail();
    $this->post(route('sales.proformas.store'), validProformaPayload(['salesperson_id' => $sp->id, 'currency_id' => $usd->id]))
        ->assertSessionHasErrors(['currency_id' => 'No hay tasa de cambio registrada para USD en '.now()->format('d/m/Y').' ni fechas anteriores.']);
    ExchangeRate::factory()->create(['currency_id' => $usd->id, 'rate_date' => now()->subDays(2)->toDateString(), 'rate' => '60.500000']);
    $this->post(route('sales.proformas.store'), validProformaPayload(['salesperson_id' => $sp->id, 'currency_id' => $usd->id]))->assertRedirect();
    $p = Proforma::firstOrFail();
    expect($p->exchange_rate)->toBe('60.500000')->and($p->total)->toBe('236.00')->and($p->total_base)->toBe('14278.00');
});

test('only drafts can be edited or deleted', function () {
    actingAsRole('owner');
    $draft = Proforma::factory()->create();
    $sent = Proforma::factory()->sent()->create();
    $payload = validProformaPayload(['salesperson_id' => $draft->salesperson_id, 'client_id' => $draft->client_id]);

    $this->put(route('sales.proformas.update', $draft), $payload)->assertRedirect(route('sales.proformas.show', $draft))->assertSessionHas('success', "Proforma {$draft->number} actualizada");
    expect($draft->fresh()->lines()->count())->toBe(1)->and($draft->fresh()->total)->toBe('236.00');
    $this->put(route('sales.proformas.update', $sent), $payload)->assertRedirect()->assertSessionHas('error', 'Solo se pueden editar proformas en borrador');
    $this->get(route('sales.proformas.edit', $sent))->assertRedirect()->assertSessionHas('error', 'Solo se pueden editar proformas en borrador');
    $this->delete(route('sales.proformas.destroy', $sent))->assertRedirect()->assertSessionHas('error', 'Solo se pueden eliminar proformas en borrador');
    $this->delete(route('sales.proformas.destroy', $draft))->assertRedirect(route('sales.proformas.index'))->assertSessionHas('success', "Proforma {$draft->number} eliminada");
    expect(Proforma::count())->toBe(1);
});

test('transitions mark sent, approve and reject with audit rows and refuse invalid states', function () {
    $user = actingAsRole('owner');
    $p = Proforma::factory()->create();
    $this->post(route('sales.proformas.approve', $p), ['note' => 'Aprobada por correo del 01/11'])->assertRedirect()->assertSessionHas('success', "Proforma {$p->number} aprobada");
    expect($p->fresh()->status)->toBe(ProformaStatus::Approved)->and($p->fresh()->approved_by)->toBe($user->id)->and($p->fresh()->approval_note)->toBe('Aprobada por correo del 01/11')
        ->and(Activity::inLog('proformas')->where('event', 'approved')->where('subject_id', $p->id)->where('causer_id', $user->id)->exists())->toBeTrue();
    $this->post(route('sales.proformas.reject', $p), ['note' => 'x'])->assertRedirect()->assertSessionHas('error', 'La proforma está Aprobada y no admite esta acción');

    $q = Proforma::factory()->create();
    $this->post(route('sales.proformas.mark-sent', $q))->assertRedirect()->assertSessionHas('success', "Proforma {$q->number} marcada como enviada");
    expect($q->fresh()->status)->toBe(ProformaStatus::Sent)->and($q->fresh()->sent_at)->not->toBeNull();
    $this->post(route('sales.proformas.mark-sent', $q))->assertSessionHas('error', 'La proforma está Enviada y no admite esta acción');
    $this->post(route('sales.proformas.reject', $q), ['note' => 'Precio alto'])->assertSessionHas('success', "Proforma {$q->number} rechazada");
    expect($q->fresh()->status)->toBe(ProformaStatus::Rejected)->and($q->fresh()->rejection_note)->toBe('Precio alto')
        ->and(Activity::inLog('proformas')->where('event', 'rejected')->where('subject_id', $q->id)->exists())->toBeTrue();

    actingAsRole('sales');
    $this->post(route('sales.proformas.approve', Proforma::factory()->create()))->assertForbidden();
});

test('duplicate creates a new draft with the same lines and today dates', function () {
    actingAsRole('owner');
    $src = Proforma::factory()->sent()->withLines(2)->create(['issue_date' => '2026-01-10', 'valid_until' => '2026-01-20']);
    $response = $this->post(route('sales.proformas.duplicate', $src));
    $copy = Proforma::latest('id')->firstOrFail();
    $response->assertRedirect(route('sales.proformas.edit', $copy))->assertSessionHas('success', 'Proforma COT-000001 creada a partir de '.$src->number); // factory numbers are random; the sequence starts at 000001
    expect($copy->status)->toBe(ProformaStatus::Draft)->and($copy->lines()->count())->toBe(2)->and($copy->issue_date->toDateString())->toBe(now()->toDateString())
        ->and($copy->total)->toBe($src->total)->and($copy->client_id)->toBe($src->client_id)
        ->and(Activity::inLog('proformas')->where('event', 'duplicated')->where('subject_id', $copy->id)->exists())->toBeTrue();
});

test('pdf renders and sending by email attaches it, marks the draft as sent and logs the email', function () {
    $user = actingAsRole('owner', ['gmail_connected_at' => now(), 'gmail_email' => 'yo@empresa.test']);
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info');
    $p = Proforma::factory()->withLines(1)->create();

    $this->get(route('sales.proformas.pdf', $p))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->post(route('sales.proformas.send', $p), ['to' => ['cliente@x.test'], 'cc' => [], 'subject' => '', 'body' => ''])
        ->assertRedirect()->assertSessionHas('success', 'Correo enviado a cliente@x.test');
    expect($p->fresh()->status)->toBe(ProformaStatus::Sent)
        ->and(SentEmail::where('mailable_type', Proforma::class)->where('mailable_id', $p->id)->first()->attachments)->toBe([$p->number.'.pdf'])
        ->and(Activity::inLog('proformas')->where('event', 'emailed')->where('subject_id', $p->id)->exists())->toBeTrue();

    $this->post(route('sales.proformas.send', $p), ['to' => [], 'cc' => []])->assertSessionHasErrors(['to' => 'Indica al menos un destinatario.']);
    actingAsRole('owner', ['gmail_connected_at' => null]);
    Log::shouldReceive('error'); // the exception handler reports the flashed GmailNotConnectedException
    $this->post(route('sales.proformas.send', $p), ['to' => ['a@b.test']])->assertRedirect()->assertSessionHas('error', 'Conecta tu cuenta de Gmail para enviar correos desde el sistema.');
});

test('the expiry job expires sent proformas past their validity and logs each', function () {
    $old = Proforma::factory()->sent()->create(['valid_until' => now()->subDay()->toDateString()]);
    $fresh = Proforma::factory()->sent()->create(['valid_until' => now()->toDateString()]);
    $draft = Proforma::factory()->create(['valid_until' => now()->subDay()->toDateString()]);
    (new ExpireProformas)->handle();
    expect($old->fresh()->status)->toBe(ProformaStatus::Expired)->and($fresh->fresh()->status)->toBe(ProformaStatus::Sent)->and($draft->fresh()->status)->toBe(ProformaStatus::Draft)
        ->and(Activity::inLog('proformas')->where('event', 'expired')->where('subject_id', $old->id)->exists())->toBeTrue();
});

test('index filters by status and export is logged and scoped', function () {
    ['salesperson' => $mine] = salesUserWithSalesperson();
    Proforma::factory()->create(['salesperson_id' => $mine->id]);
    Proforma::factory()->sent()->create(['salesperson_id' => $mine->id]);
    Proforma::factory()->create();
    $this->get(route('sales.proformas.index', ['status' => 'sent']))->assertInertia(fn ($p) => $p->has('rows.data', 1));
    $this->get(route('sales.proformas.export', ['format' => 'csv']))->assertOk();
    expect(Activity::inLog('exports')->where('event', 'exported')->latest('id')->first()->properties['row_count'])->toBe(2);
});
