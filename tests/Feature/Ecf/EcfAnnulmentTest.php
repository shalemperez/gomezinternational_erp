<?php

use App\Actions\Sales\CancelInvoice;
use App\Enums\NcfType;
use App\Exceptions\EcfException;
use App\Exceptions\FiscalNumberingException;
use App\Jobs\SubmitEcfDocument;
use App\Models\EcfAnnulment;
use App\Models\EcfAnnulmentRange;
use App\Models\NcfSequence;
use App\Models\User;
use App\Services\Ecf\EcfSequenceAnnulmentService;
use App\Services\Ecf\XsdValidator;
use App\Services\Fiscal\FiscalNumberingService;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    (new DocumentSequenceSeeder)->run();
    Bus::fake([SubmitEcfDocument::class]);
    ecfMode();
    activeCertificate();
});

/** Caps the test-environment sequence of the type at the given end so tail ranges stay small. */
function capSequence(string $type, int $rangeTo): NcfSequence
{
    $sequence = NcfSequence::query()->where('ncf_type', $type)->where('environment', 'test')->firstOrFail();
    $sequence->update(['range_to' => $rangeTo]);

    return $sequence;
}

test('a range is refused unless it is the unused tail of a sequence of the environment', function () {
    $actor = User::factory()->create();
    issuedInvoice(); // consumes E310000000001
    capSequence('E31', 10);
    $service = app(EcfSequenceAnnulmentService::class);
    $tailOnly = 'Solo se puede anular el tramo final sin usar de la secuencia: el rango debe empezar en E310000000002 o después y terminar en E310000000010.';
    expect(fn () => $service->addRange(NcfType::E31, 1, 10, 'sobrantes', $actor))->toThrow(EcfException::class, $tailOnly);
    expect(fn () => $service->addRange(NcfType::E31, 2, 5, 'x', $actor))->toThrow(EcfException::class, $tailOnly);
    expect(fn () => $service->addRange(NcfType::E31, 20000000, 20000005, 'x', $actor))->toThrow(EcfException::class, 'El rango no pertenece a ninguna secuencia E31 registrada para este ambiente.');
    expect(fn () => $service->addRange(NcfType::B01, 2, 5, 'x', $actor))->toThrow(EcfException::class, 'Solo se anulan secuencias electrónicas.');
    $service->addRange(NcfType::E31, 6, 10, 'sobrantes', $actor);
    expect($service->pendingRanges())->toHaveCount(1);
});

test('adding a tail range shrinks the sequence so its numbers are never issued', function () {
    $actor = User::factory()->create();
    $sequence = capSequence('E31', 10);
    $range = app(EcfSequenceAnnulmentService::class)->addRange(NcfType::E31, 8, 10, 'sobrantes', $actor);
    $sequence->refresh();
    expect($sequence->range_to)->toBe(7)->and($range->original_range_to)->toBe(10)->and($range->ncf_sequence_id)->toBe($sequence->id)->and($sequence->exhausted_at)->toBeNull();
    $sequence->update(['next_number' => 7]);
    expect(app(FiscalNumberingService::class)->assign(NcfType::B01, today())->ncf)->toBe('E310000000007');
    expect(fn () => app(FiscalNumberingService::class)->assign(NcfType::B01, today()))->toThrow(FiscalNumberingException::class, 'La secuencia de E31 está agotada');
    $whole = capSequence('E34', 3);
    app(EcfSequenceAnnulmentService::class)->addRange(NcfType::E34, 1, 3, 'nc no usadas', $actor);
    expect($whole->fresh()->range_to)->toBe(0)->and($whole->fresh()->exhausted_at)->not->toBeNull();
});

test('deleting a pending tail range restores the end of the sequence', function () {
    $sequence = capSequence('E31', 10);
    $range = app(EcfSequenceAnnulmentService::class)->addRange(NcfType::E31, 1, 10, 'x', User::factory()->create());
    expect($sequence->fresh()->exhausted_at)->not->toBeNull();
    actingAsRole('accounting');
    $this->delete(route('fiscal.ecf-annulments.ranges.destroy', $range))->assertRedirect(route('fiscal.ecf-annulments.index'))->assertSessionHas('success', 'Rango eliminado');
    expect($sequence->fresh()->range_to)->toBe(10)->and($sequence->fresh()->exhausted_at)->toBeNull()->and(EcfAnnulmentRange::query()->whereKey($range->id)->exists())->toBeFalse()
        ->and(Activity::inLog('ecf_annulments')->where('event', 'range_deleted')->exists())->toBeTrue();
});

test('a sequence whose tail is under annulment cannot be extended', function () {
    $sequence = capSequence('E31', 10);
    app(EcfSequenceAnnulmentService::class)->addRange(NcfType::E31, 6, 10, 'sobrantes', User::factory()->create());
    actingAsRole('owner');
    $this->put(route('admin.ncf-sequences.update', $sequence), validNcfSequencePayload(['ncf_type' => 'E31', 'range_from' => 1, 'range_to' => 10]))
        ->assertSessionHasErrors(['range_to' => 'El final de esta secuencia está en anulación ante la DGII; no se puede ampliar.']);
    expect($sequence->fresh()->range_to)->toBe(5);
});

test('sending builds a signed anecf that validates against the xsd, posts it and keeps the sequence capped', function () {
    ecfMode('dgii');
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/anulacionrangos/api/operaciones/anularrango' => Http::response(['rnc' => '131880738', 'codigo' => '1', 'nombre' => 'Aceptado', 'mensajes' => []])]);
    $actor = User::factory()->create();
    capSequence('E31', 10);
    capSequence('E34', 3);
    $service = app(EcfSequenceAnnulmentService::class);
    $r1 = $service->addRange(NcfType::E31, 1, 10, 'rango inicial', $actor);
    $r2 = $service->addRange(NcfType::E34, 1, 3, 'nc no usadas', $actor);
    $annulment = $service->send([$r1->id, $r2->id], $actor);
    $doc = new DOMDocument;
    $doc->loadXML(Storage::disk('local')->get($annulment->xml_path));
    app(XsdValidator::class)->validate($doc, 'anecf');
    $x = new DOMXPath($doc);
    expect($annulment->status)->toBe('accepted')->and($x->evaluate('string(//Encabezado/CantidadeNCFAnulados)'))->toBe('13')->and($x->query('//DetalleAnulacion/Anulacion')->length)->toBe(2)
        ->and($x->evaluate('string(//Anulacion[NoLinea=1]/TipoeCF)'))->toBe('31')->and($x->evaluate('string(//Anulacion[NoLinea=1]//SecuenciaeNCFDesde)'))->toBe('E310000000001')->and($x->evaluate('string(//Anulacion[NoLinea=1]//SecuenciaeNCFHasta)'))->toBe('E310000000010')
        ->and($x->evaluate('string(//Anulacion[NoLinea=2]/CantidadeNCFAnulados)'))->toBe('3')
        ->and(NcfSequence::query()->where('ncf_type', 'E31')->where('environment', 'test')->value('range_to'))->toBe(0)
        ->and(Activity::inLog('ecf_annulments')->where('subject_id', $annulment->id)->where('event', 'accepted')->exists())->toBeTrue();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anularrango') && $r->hasFile('xml') && str_contains((string) $r->body(), '<ANECF>'));
    expect(fn () => app(FiscalNumberingService::class)->assign(NcfType::B01, today()))->toThrow(FiscalNumberingException::class, 'La secuencia de E31 está agotada');
});

test('a dgii rejection restores the sequence end, keeps the tail range as history and returns cancelled numbers to pending', function () {
    ecfMode('dgii');
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/anulacionrangos/api/operaciones/anularrango' => Http::response(['rnc' => '131880738', 'codigo' => '2', 'nombre' => 'Rechazado', 'mensajes' => ['Las secuencias que está intentando anular han sido utilizadas']])]);
    $invoice = issuedInvoice(); // E310000000001
    app(CancelInvoice::class)->handle($invoice, User::factory()->create(), 'dup');
    $single = EcfAnnulmentRange::query()->whereNotNull('ecf_document_id')->firstOrFail();
    capSequence('E31', 10);
    $actor = User::factory()->create();
    $service = app(EcfSequenceAnnulmentService::class);
    $tail = $service->addRange(NcfType::E31, 2, 10, 'x', $actor);
    $annulment = $service->send([$single->id, $tail->id], $actor);
    $sequence = NcfSequence::query()->where('ncf_type', 'E31')->where('environment', 'test')->firstOrFail();
    expect($annulment->status)->toBe('rejected')->and($annulment->response['mensajes'][0])->toContain('utilizadas')
        ->and($tail->fresh()->ecf_annulment_id)->toBe($annulment->id)->and($single->fresh()->ecf_annulment_id)->toBeNull()
        ->and($service->pendingRanges()->pluck('id')->all())->toBe([$single->id])
        ->and($sequence->range_to)->toBe(10)->and($sequence->next_number)->toBe(2)->and($sequence->exhausted_at)->toBeNull();
});

test('the annulments page lists pending ranges including invoices cancelled before send and requires the annul permission', function () {
    $invoice = issuedInvoice();
    app(CancelInvoice::class)->handle($invoice, User::factory()->create(), 'dup');
    capSequence('E31', 10);
    actingAsRole('sales');
    $this->get(route('fiscal.ecf-annulments.index'))->assertForbidden();
    actingAsRole('accounting');
    $this->get(route('fiscal.ecf-annulments.index'))->assertOk()->assertInertia(fn ($p) => $p->component('fiscal/ecf-annulments/index')->has('pending', 1)->where('pending.0.encf_from', 'E310000000001')->where('pending.0.document_number', $invoice->number)->where('environment_label', 'Pruebas (TesteCF)'));
    $this->post(route('fiscal.ecf-annulments.ranges.store'), ['ncf_type' => 'E31', 'range_from' => 2, 'range_to' => 10, 'reason' => 'sobrantes'])->assertRedirect()->assertSessionHas('success', 'Rango agregado a la anulación pendiente');
    $this->post(route('fiscal.ecf-annulments.ranges.store'), ['ncf_type' => 'E31', 'range_from' => 5, 'range_to' => 4, 'reason' => 'x'])->assertSessionHasErrors('range_to');
    $ids = EcfAnnulmentRange::query()->whereNull('ecf_annulment_id')->pluck('id')->all();
    $this->post(route('fiscal.ecf-annulments.send'), ['range_ids' => $ids])->assertRedirect()->assertSessionHas('success', 'Anulación enviada: Aceptada');
    expect(EcfAnnulment::query()->where('status', 'accepted')->count())->toBe(1)->and(EcfAnnulmentRange::query()->whereNull('ecf_annulment_id')->count())->toBe(0);
});

test('a provider failure marks the annulment failed and restores the sequence end', function () {
    ecfMode('dgii');
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/anulacionrangos/api/operaciones/anularrango' => fn () => throw new ConnectionException('cURL error 28: timeout')]);
    capSequence('E31', 10);
    $actor = User::factory()->create();
    $service = app(EcfSequenceAnnulmentService::class);
    $range = $service->addRange(NcfType::E31, 1, 10, 'x', $actor);
    expect(fn () => $service->send([$range->id], $actor))->toThrow(EcfException::class);
    $failed = EcfAnnulment::query()->where('status', 'failed')->firstOrFail();
    expect($range->fresh()->ecf_annulment_id)->toBe($failed->id)->and(app(EcfSequenceAnnulmentService::class)->pendingRanges())->toHaveCount(0)
        ->and(NcfSequence::query()->where('ncf_type', 'E31')->where('environment', 'test')->value('range_to'))->toBe(10);
});

test('a rejected annulment keeps its counts in the history', function () {
    ecfMode('dgii');
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/anulacionrangos/api/operaciones/anularrango' => Http::response(['rnc' => '131880738', 'codigo' => '2', 'nombre' => 'Rechazado', 'mensajes' => ['Las secuencias que está intentando anular han sido utilizadas']])]);
    capSequence('E31', 10);
    $actor = User::factory()->create();
    $service = app(EcfSequenceAnnulmentService::class);
    $range = $service->addRange(NcfType::E31, 1, 10, 'x', $actor);
    $service->send([$range->id], $actor);
    actingAsRole('accounting');
    $this->get(route('fiscal.ecf-annulments.index'))->assertOk()->assertInertia(fn ($p) => $p->component('fiscal/ecf-annulments/index')
        ->where('history.0.status', 'rejected')->where('history.0.ranges_count', 1)->where('history.0.quantity', (int) $range->quantity));
});

test('ranges created by cancelled invoices cannot be deleted', function () {
    $invoice = issuedInvoice();
    app(CancelInvoice::class)->handle($invoice, User::factory()->create(), 'dup');
    $range = EcfAnnulmentRange::query()->whereNull('ecf_annulment_id')->whereNotNull('ecf_document_id')->firstOrFail();
    actingAsRole('accounting');
    $this->delete(route('fiscal.ecf-annulments.ranges.destroy', $range))->assertRedirect(route('fiscal.ecf-annulments.index'))
        ->assertSessionHas('error', 'Este rango proviene de una factura anulada y debe enviarse a la DGII.');
    expect(EcfAnnulmentRange::query()->whereKey($range->id)->exists())->toBeTrue();
});
