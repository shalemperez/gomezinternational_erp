<?php

use App\Exceptions\EcfException;
use App\Models\ReceivedEcf;
use App\Models\User;
use App\Services\Ecf\CommercialApprovalService;
use App\Services\Ecf\XsdValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    ecfMode('dgii');
    activeCertificate();
    $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', supplierEcfXml())]);
});

test('approving a received e-cf signs an acecf, posts it to the dgii and to the issuer url from the directory', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/aprobacioncomercial/api/aprobacioncomercial' => Http::response(['mensaje' => 'OK']),
        'https://ecf.dgii.gov.do/testecf/consultadirectorio/api/consultas/obtenerdirectorioporrnc?RNC=101010101' => Http::response([['nombre' => 'Proveedor Prueba SRL', 'rnc' => '101010101', 'urlRecepcion' => 'https://prov.test/fe', 'urlAceptacion' => 'https://prov.test', 'urlOpcional' => null]]),
        'https://prov.test/fe/aprobacioncomercial/api/ecf' => Http::response('', 200)]);
    $received = ReceivedEcf::query()->firstOrFail();
    $actor = User::factory()->create();
    $received = app(CommercialApprovalService::class)->decide($received, $actor, true, null);
    $doc = new DOMDocument;
    $doc->loadXML(Storage::disk('local')->get($received->acecf_path));
    app(XsdValidator::class)->validate($doc, 'acecf');
    $x = new DOMXPath($doc);
    expect($received->commercial_status)->toBe('approved')->and($received->commercial_by)->toBe($actor->id)->and($received->commercial_sent_at)->not->toBeNull()
        ->and($x->evaluate('string(//DetalleAprobacionComercial/Estado)'))->toBe('1')->and($x->evaluate('string(//DetalleAprobacionComercial/eNCF)'))->toBe('E310000000777')->and($x->evaluate('string(//DetalleAprobacionComercial/MontoTotal)'))->toBe('1180.00')
        ->and($received->notes)->toContain('Entrega al emisor: HTTP 200')
        ->and(Activity::inLog('received_ecfs')->where('subject_id', $received->id)->where('event', 'commercially_approved')->exists())->toBeTrue();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'aprobacioncomercial/api/aprobacioncomercial') && $r->hasFile('xml', null, '131880738E310000000777.xml'));
    Http::assertSent(fn (Request $r) => $r->url() === 'https://prov.test/fe/aprobacioncomercial/api/ecf' && ! $r->hasHeader('Authorization'));
});

test('a rejection requires a reason, a dgii refusal keeps the decision pending and an unreachable issuer is tolerated', function () {
    $received = ReceivedEcf::query()->firstOrFail();
    $actor = User::factory()->create();
    expect(fn () => app(CommercialApprovalService::class)->decide($received, $actor, false, null))->toThrow(EcfException::class, 'Indica el motivo del rechazo comercial.');
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/aprobacioncomercial/api/aprobacioncomercial' => Http::response(['mensaje' => 'Factura no encontrada para esta Aprobación comercial.'], 400)]);
    expect(fn () => app(CommercialApprovalService::class)->decide($received, $actor, false, 'Mercancía no recibida'))->toThrow(EcfException::class, 'La DGII rechazó la aprobación comercial: Factura no encontrada para esta Aprobación comercial.');
    expect($received->fresh()->commercial_status)->toBe('pending');
    Http::swap(new Factory);
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/aprobacioncomercial/api/aprobacioncomercial' => Http::response(['mensaje' => 'OK']),
        'https://ecf.dgii.gov.do/testecf/consultadirectorio/api/consultas/obtenerdirectorioporrnc?RNC=101010101' => Http::response([['rnc' => '101010101', 'urlAceptacion' => 'https://down.test']]),
        'https://down.test/fe/aprobacioncomercial/api/ecf' => fn () => throw new ConnectionException('refused')]);
    $received = app(CommercialApprovalService::class)->decide($received->fresh(), $actor, false, 'Mercancía no recibida');
    expect($received->commercial_status)->toBe('rejected')->and($received->commercial_reason)->toBe('Mercancía no recibida')->and($received->notes)->toContain('Entrega al emisor: sin respuesta');
    expect(fn () => app(CommercialApprovalService::class)->decide($received, $actor, true, null))->toThrow(EcfException::class, 'Este e-CF ya tiene una decisión comercial.');
});

test('the approve route enforces the permission and validates the decision', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/aprobacioncomercial/api/aprobacioncomercial' => Http::response(['mensaje' => 'OK']), 'https://ecf.dgii.gov.do/testecf/consultadirectorio/*' => Http::response([])]);
    $received = ReceivedEcf::query()->firstOrFail();
    actingAsRole('management');
    $this->post(route('fiscal.received-ecf.approve', $received), ['decision' => 'approve'])->assertForbidden();
    actingAsRole('accounting');
    $this->post(route('fiscal.received-ecf.approve', $received), ['decision' => 'reject'])->assertSessionHasErrors('reason');
    $this->post(route('fiscal.received-ecf.approve', $received), ['decision' => 'approve'])->assertRedirect()->assertSessionHas('success', 'Aprobación comercial enviada a la DGII');
    expect($received->fresh()->notes)->toContain('Entrega al emisor: sin URL');
});

test('a decision being sent blocks a second decision and a dgii failure releases it', function () {
    $received = ReceivedEcf::query()->firstOrFail();
    $actor = User::factory()->create();
    $received->forceFill(['commercial_status' => 'sending'])->save();
    expect(fn () => app(CommercialApprovalService::class)->decide($received, $actor, true, null))->toThrow(EcfException::class, 'Ya se está enviando una decisión comercial para este e-CF.');
    $received->forceFill(['commercial_status' => 'pending'])->save();
    $statusDuringCall = null;
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/aprobacioncomercial/api/aprobacioncomercial' => function () use ($received, &$statusDuringCall) {
        $statusDuringCall = $received->fresh()->commercial_status;

        return Http::response(['mensaje' => 'Error'], 400);
    }]);
    expect(fn () => app(CommercialApprovalService::class)->decide($received->fresh(), $actor, true, null))->toThrow(EcfException::class, 'La DGII rechazó la aprobación comercial: Error');
    expect($statusDuringCall)->toBe('sending')->and($received->fresh()->commercial_status)->toBe('pending')->and($received->fresh()->commercialStatusLabel())->toBe('Pendiente');
});
