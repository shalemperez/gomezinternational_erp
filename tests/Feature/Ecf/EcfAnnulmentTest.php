<?php

use App\Actions\Sales\CancelInvoice;
use App\Enums\NcfType;
use App\Exceptions\EcfException;
use App\Jobs\SubmitEcfDocument;
use App\Models\EcfAnnulment;
use App\Models\EcfAnnulmentRange;
use App\Models\NcfSequence;
use App\Models\User;
use App\Services\Ecf\EcfSequenceAnnulmentService;
use App\Services\Ecf\XsdValidator;
use App\Services\Fiscal\FiscalNumberingService;
use Database\Seeders\DocumentSequenceSeeder;
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

test('a pending range is refused when it contains used e-ncfs, lies outside a sequence or overlaps another pending range', function () {
    $actor = User::factory()->create();
    issuedInvoice(); // consumes E310000000001
    $service = app(EcfSequenceAnnulmentService::class);
    expect(fn () => $service->addRange(NcfType::E31, 1, 5, 'sobrantes', $actor))->toThrow(EcfException::class, 'El rango incluye e-NCF ya utilizados (E310000000001).');
    expect(fn () => $service->addRange(NcfType::E31, 20000000, 20000005, 'x', $actor))->toThrow(EcfException::class, 'El rango no pertenece a ninguna secuencia E31 registrada para este ambiente.');
    expect(fn () => $service->addRange(NcfType::B01, 2, 5, 'x', $actor))->toThrow(EcfException::class, 'Solo se anulan secuencias electrónicas.');
    $service->addRange(NcfType::E31, 2, 5, 'sobrantes', $actor);
    expect(fn () => $service->addRange(NcfType::E31, 4, 9, 'x', $actor))->toThrow(EcfException::class, 'El rango se solapa con una anulación pendiente.');
    expect($service->pendingRanges())->toHaveCount(1);
});

test('sending builds a signed anecf that validates against the xsd, posts it and advances the sequence', function () {
    ecfMode('dgii');
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/anulacionrangos/api/operaciones/anularrango' => Http::response(['rnc' => '131880738', 'codigo' => '1', 'nombre' => 'Aceptado', 'mensajes' => []])]);
    $actor = User::factory()->create();
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
        ->and(NcfSequence::query()->where('ncf_type', 'E31')->where('environment', 'test')->value('next_number'))->toBe(11)
        ->and(Activity::inLog('ecf_annulments')->where('subject_id', $annulment->id)->where('event', 'accepted')->exists())->toBeTrue();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anularrango') && $r->hasFile('xml') && str_contains((string) $r->body(), '<ANECF>'));
    expect(app(FiscalNumberingService::class)->assign(NcfType::B01, today())->ncf)->toBe('E310000000011');
});

test('a dgii rejection keeps the ranges pending for a new attempt', function () {
    ecfMode('dgii');
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/anulacionrangos/api/operaciones/anularrango' => Http::response(['rnc' => '131880738', 'codigo' => '2', 'nombre' => 'Rechazado', 'mensajes' => ['Las secuencias que está intentando anular han sido utilizadas']])]);
    $actor = User::factory()->create();
    $service = app(EcfSequenceAnnulmentService::class);
    $r = $service->addRange(NcfType::E31, 1, 10, 'x', $actor);
    $annulment = $service->send([$r->id], $actor);
    expect($annulment->status)->toBe('rejected')->and($annulment->response['mensajes'][0])->toContain('utilizadas')->and($r->fresh()->ecf_annulment_id)->toBeNull()->and($service->pendingRanges())->toHaveCount(1)
        ->and(NcfSequence::query()->where('ncf_type', 'E31')->where('environment', 'test')->value('next_number'))->toBe(1);
});

test('the annulments page lists pending ranges including invoices cancelled before send and requires the annul permission', function () {
    $invoice = issuedInvoice();
    app(CancelInvoice::class)->handle($invoice, User::factory()->create(), 'dup');
    actingAsRole('sales');
    $this->get(route('fiscal.ecf-annulments.index'))->assertForbidden();
    actingAsRole('accounting');
    $this->get(route('fiscal.ecf-annulments.index'))->assertOk()->assertInertia(fn ($p) => $p->component('fiscal/ecf-annulments/index')->has('pending', 1)->where('pending.0.encf_from', 'E310000000001')->where('pending.0.document_number', $invoice->number)->where('environment_label', 'Pruebas (TesteCF)'));
    $this->post(route('fiscal.ecf-annulments.ranges.store'), ['ncf_type' => 'E31', 'range_from' => 2, 'range_to' => 4, 'reason' => 'sobrantes'])->assertRedirect()->assertSessionHas('success', 'Rango agregado a la anulación pendiente');
    $this->post(route('fiscal.ecf-annulments.ranges.store'), ['ncf_type' => 'E31', 'range_from' => 5, 'range_to' => 4, 'reason' => 'x'])->assertSessionHasErrors('range_to');
    $ids = EcfAnnulmentRange::query()->whereNull('ecf_annulment_id')->pluck('id')->all();
    $this->post(route('fiscal.ecf-annulments.send'), ['range_ids' => $ids])->assertRedirect()->assertSessionHas('success', 'Anulación enviada: Aceptada');
    expect(EcfAnnulment::query()->where('status', 'accepted')->count())->toBe(1)->and(EcfAnnulmentRange::query()->whereNull('ecf_annulment_id')->count())->toBe(0);
});
