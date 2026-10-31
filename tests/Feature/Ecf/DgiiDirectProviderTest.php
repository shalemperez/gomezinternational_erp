<?php

use App\Enums\EcfStatus;
use App\Enums\NcfType;
use App\Exceptions\EcfCertificateException;
use App\Exceptions\EcfException;
use App\Jobs\PollEcfStatus;
use App\Jobs\SubmitEcfDocument;
use App\Models\Client;
use App\Models\EcfCertificate;
use App\Models\EcfDocument;
use App\Models\NcfSequence;
use App\Models\Product;
use App\Services\Ecf\DgiiClient;
use App\Services\Ecf\EcfService;
use App\Support\Fiscal\FiscalNumber;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    (new DocumentSequenceSeeder)->run();
    ecfSettings('dgii');
    activeCertificate();
});

function electronicSequenceId(string $type): int
{
    return (int) NcfSequence::query()->where('ncf_type', $type)->value('id');
}

test('authentication fetches the seed, signs it and caches the bearer token', function () {
    Http::fake(fakeDgiiAuth());
    $client = app(DgiiClient::class);
    expect($client->token())->toBe('tok-1')->and($client->token())->toBe('tok-1');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/validarsemilla') && $r->hasFile('xml') && str_contains((string) $r->body(), '<SignatureValue>') && str_contains((string) $r->body(), '<valor>abc-123</valor>'));
});

test('an e-cf submission posts the signed xml as multipart with the standard file name and stores the trackid', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::response(['trackId' => 'TRK-1', 'error' => null, 'mensaje' => null])]);
    $service = app(EcfService::class);
    $doc = $service->create(issuedInvoice(), new FiscalNumber('E310000000001', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    $doc = $service->submit($doc);
    expect($doc->status)->toBe(EcfStatus::Sent)->and($doc->track_id)->toBe('TRK-1')->and($doc->provider)->toBe('dgii')
        ->and($doc->transmissions()->where('kind', 'send')->value('http_status'))->toBe(200);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/recepcion/api/facturaselectronicas') && $r->hasHeader('Authorization', 'Bearer tok-1') && $r->hasFile('xml', null, '131880738E310000000001.xml'));
});

test('rfce submissions post the summary to recepcionfc and apply the synchronous result', function () {
    Http::fake([...fakeDgiiAuth(), 'https://fc.dgii.gov.do/testecf/recepcionfc/api/recepcion/ecf' => Http::response(['codigo' => 1, 'estado' => 'Aceptado', 'mensajes' => [], 'encf' => 'E320000000001', 'secuenciaUtilizada' => true])]);
    $client = Client::factory()->create(['tax_id' => null, 'tax_id_type' => 'none', 'taxpayer_type' => 'consumidor_final']);
    $service = app(EcfService::class);
    $doc = $service->submit($service->create(issuedInvoice(['client_id' => $client->id]), new FiscalNumber('E320000000001', NcfType::E32, null, electronicSequenceId('E32')), null));
    expect($doc->status)->toBe(EcfStatus::Accepted)->and($doc->flow)->toBe('rfce')->and($doc->track_id)->toBeNull()->and($doc->dgii_code)->toBe(1);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fc.dgii.gov.do/testecf/recepcionfc') && $r->hasFile('xml', null, 'RFCE-131880738E320000000001.xml') && str_contains((string) $r->body(), '<RFCE>'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/recepcion/api/facturaselectronicas'));
});

test('consumo invoices at or above the threshold are sent as full e-cf', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::response(['trackId' => 'TRK-2'])]);
    $client = Client::factory()->create(['tax_id' => '00112345678', 'tax_id_type' => 'cedula', 'taxpayer_type' => 'consumidor_final', 'credit_limit' => '0']);
    $invoice = issuedInvoice(['client_id' => $client->id, 'lines' => [['product_id' => Product::factory()->create(['itbis_rate' => '18.00'])->id, 'description' => '', 'quantity' => '1000', 'unit_price' => '250', 'discount_percent' => '0']]]);
    $service = app(EcfService::class);
    $doc = $service->submit($service->create($invoice, new FiscalNumber('E320000000002', NcfType::E32, null, electronicSequenceId('E32')), null));
    expect($invoice->total)->toBe('295000.00')->and($doc->flow)->toBe('ecf')->and($doc->status)->toBe(EcfStatus::Sent)->and($doc->qr_url)->toContain('/consultatimbre?');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'recepcionfc'));
});

test('polling maps dgii codes to statuses and stores messages', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/consultaresultado/api/consultas/estado?trackid=TRK-A' => Http::response(['trackId' => 'TRK-A', 'codigo' => 4, 'estado' => 'Aceptado Condicional', 'rnc' => '131880738', 'eNCF' => 'E310000000001', 'secuenciaUtilizada' => true, 'fechaRecepcion' => '01-11-2026 10:00:00', 'mensajes' => [['valor' => 'Advertencia X', 'codigo' => 120]]]),
        'https://ecf.dgii.gov.do/testecf/consultaresultado/api/consultas/estado?trackid=TRK-B' => Http::response(['trackId' => 'TRK-B', 'codigo' => 3, 'estado' => 'En Proceso', 'mensajes' => []])]);
    $a = EcfDocument::factory()->create(['status' => 'sent', 'track_id' => 'TRK-A', 'provider' => 'dgii', 'encf' => 'E310000000001']);
    $b = EcfDocument::factory()->create(['status' => 'sent', 'track_id' => 'TRK-B', 'provider' => 'dgii', 'encf' => 'E310000000002']);
    $service = app(EcfService::class);
    $a = $service->poll($a);
    $b = $service->poll($b);
    expect($a->status)->toBe(EcfStatus::ConditionallyAccepted)->and($a->dgii_code)->toBe(4)->and($a->dgii_messages)->toBe([['codigo' => '120', 'valor' => 'Advertencia X']])->and($a->accepted_at)->not->toBeNull()
        ->and($b->status)->toBe(EcfStatus::InProcess)->and($b->next_attempt_at)->not->toBeNull();
});

test('a 400 from reception is recorded as a rejection with the dgii message', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::response(['trackId' => null, 'error' => 'XSD', 'mensaje' => 'La estructura del archivo XML no es válido, favor proveer un XML con una estructura válida'], 400)]);
    $service = app(EcfService::class);
    $doc = $service->submit($service->create(issuedInvoice(), new FiscalNumber('E310000000003', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null));
    expect($doc->status)->toBe(EcfStatus::Rejected)->and($doc->dgii_messages[0]['valor'])->toContain('estructura')->and($doc->transmissions()->first()->http_status)->toBe(400);
});

test('an expired token is refreshed once on 401', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::sequence()->push([], 401)->push(['trackId' => 'TRK-R'], 200)]);
    $service = app(EcfService::class);
    $doc = $service->submit($service->create(issuedInvoice(), new FiscalNumber('E310000000004', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null));
    expect($doc->status)->toBe(EcfStatus::Sent)->and($doc->track_id)->toBe('TRK-R');
    Http::assertSentCount(6);
});

test('a network failure surfaces as an ecf exception naming the dgii', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => fn () => throw new ConnectionException('cURL error 28: timeout')]);
    $service = app(EcfService::class);
    $doc = $service->create(issuedInvoice(), new FiscalNumber('E310000000005', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    expect(fn () => $service->submit($doc))->toThrow(EcfException::class, 'No se pudo conectar con la DGII (recepcion): cURL error 28: timeout');
});

test('without an active certificate preparing in dgii mode is refused', function () {
    EcfCertificate::query()->update(['is_active' => false]);
    expect(fn () => DB::transaction(fn () => app(EcfService::class)->create(issuedInvoice(), new FiscalNumber('E310000000006', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null)))
        ->toThrow(EcfCertificateException::class, 'No hay un certificado digital activo');
    expect(EcfDocument::count())->toBe(0);
});

test('directory lookup returns the receiver urls of a taxpayer or null', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/consultadirectorio/api/consultas/obtenerdirectorioporrnc?RNC=101010101' => Http::response([['nombre' => 'Proveedor SRL', 'rnc' => '101010101', 'urlRecepcion' => 'https://prov.test/fe', 'urlAceptacion' => 'https://prov.test/fe', 'urlOpcional' => null]]),
        'https://ecf.dgii.gov.do/testecf/consultadirectorio/api/consultas/obtenerdirectorioporrnc?RNC=999999999' => Http::response([])]);
    $client = app(DgiiClient::class);
    expect($client->directoryByRnc('101010101')['urlAceptacion'])->toBe('https://prov.test/fe')->and($client->directoryByRnc('999999999'))->toBeNull();
});

test('a seed request failure raises an ecf exception', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/autenticacion/api/autenticacion/semilla' => Http::response('<html>Not found</html>', 404)]);
    $service = app(EcfService::class);
    $doc = $service->create(issuedInvoice(), new FiscalNumber('E310000000007', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    expect(fn () => $service->submit($doc))->toThrow(EcfException::class, 'no entregó la semilla (404)');
});

test('a seed body that is not xml raises an ecf exception', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/autenticacion/api/autenticacion/semilla' => Http::response('not xml', 200)]);
    expect(fn () => app(DgiiClient::class)->authenticate())->toThrow(EcfException::class, 'La DGII devolvió una semilla no válida.');
});

test('transient dgii answers are retried, not rejected', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::sequence()->push('{}', 429)->push('{}', 200)]);
    $service = app(EcfService::class);
    $doc = $service->create(issuedInvoice(), new FiscalNumber('E310000000008', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    expect(fn () => $service->submit($doc))->toThrow(EcfException::class, 'Respuesta inesperada de la DGII (429)')
        ->and(fn () => $service->submit($doc))->toThrow(EcfException::class, 'Respuesta inesperada de la DGII (200)');
});

test('a 400 answer is a rejection with the dgii message', function () {
    Http::fake([...fakeDgiiAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::response('{"mensaje":"Formato inválido"}', 400)]);
    $service = app(EcfService::class);
    $doc = $service->submit($service->create(issuedInvoice(), new FiscalNumber('E310000000009', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null));
    expect($doc->status)->toBe(EcfStatus::Rejected)->and($doc->dgii_messages[0]['valor'])->toBe('Formato inválido');
});

test('a retry dispatched while the submission is running is dropped by the unique lock', function () {
    $service = app(EcfService::class);
    $doc = $service->create(issuedInvoice(), new FiscalNumber('E310000000010', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    Http::fake([...fakeDgiiAuth(),
        'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => function () use ($doc) {
            SubmitEcfDocument::dispatch(EcfDocument::query()->findOrFail($doc->id));

            return Http::response(['trackId' => 'TRK-U']);
        },
        'https://ecf.dgii.gov.do/testecf/consultaresultado/api/consultas/estado?trackid=TRK-U' => Http::response(['trackId' => 'TRK-U', 'codigo' => 1, 'estado' => 'Aceptado', 'mensajes' => []]),
    ]);
    SubmitEcfDocument::dispatch($doc);
    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/recepcion/api/facturaselectronicas'))->count())->toBe(1)
        ->and($doc->fresh()->status)->toBe(EcfStatus::Accepted)->and($doc->fresh()->attempts)->toBe(2); // submit claim + chained poll claim
});

test('a resend after an unclear failure asks the dgii for the e-ncf and applies its accepted result instead of sending again', function () {
    $service = app(EcfService::class);
    $doc = $service->create(issuedInvoice(), new FiscalNumber('E310000000011', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    $doc->forceFill(['attempts' => 1, 'last_error' => 'Error HTTP 503 de la DGII en recepcion'])->save();
    Http::fake([...fakeDgiiAuth(),
        'https://ecf.dgii.gov.do/testecf/consultatrackids/api/trackids/consulta?rncemisor=131880738&encf=E310000000011' => Http::response([['trackId' => 'TRK-OLD', 'estado' => 'Rechazado', 'fechaRecepcion' => '01-11-2026 10:00:00'], ['trackId' => 'TRK-FIRST', 'estado' => 'Aceptado', 'fechaRecepcion' => '01-11-2026 09:00:00']]),
        'https://ecf.dgii.gov.do/testecf/consultaresultado/api/consultas/estado?trackid=TRK-OLD' => Http::response(['trackId' => 'TRK-OLD', 'codigo' => 2, 'estado' => 'Rechazado', 'mensajes' => [['valor' => 'e-NCF duplicado', 'codigo' => 1]]]),
        'https://ecf.dgii.gov.do/testecf/consultaresultado/api/consultas/estado?trackid=TRK-FIRST' => Http::response(['trackId' => 'TRK-FIRST', 'codigo' => 1, 'estado' => 'Aceptado', 'mensajes' => []]),
    ]);
    (new SubmitEcfDocument($doc))->handle($service);
    $doc->refresh();
    expect($doc->status)->toBe(EcfStatus::Accepted)->and($doc->track_id)->toBe('TRK-FIRST')->and($doc->transmissions()->where('kind', 'lookup')->count())->toBe(1);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/recepcion/api/facturaselectronicas'));
});

test('a resend whose e-ncf the dgii does not know is sent again and a first submission never asks', function () {
    $service = app(EcfService::class);
    $first = $service->create(issuedInvoice(), new FiscalNumber('E310000000012', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    $retry = $service->create(issuedInvoice(), new FiscalNumber('E310000000013', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    $retry->forceFill(['attempts' => 1])->save();
    Http::fake([...fakeDgiiAuth(),
        'https://ecf.dgii.gov.do/testecf/consultatrackids/api/trackids/consulta?rncemisor=131880738&encf=E310000000013' => Http::response([]),
        'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::response(['trackId' => 'TRK-N']),
    ]);
    Bus::fake([PollEcfStatus::class]);
    (new SubmitEcfDocument($first))->handle($service);
    (new SubmitEcfDocument($retry))->handle($service);
    expect($first->fresh()->status)->toBe(EcfStatus::Sent)->and($retry->fresh()->status)->toBe(EcfStatus::Sent)->and($retry->fresh()->attempts)->toBe(2);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'consultatrackids') && str_contains($r->url(), 'encf=E310000000013'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'encf=E310000000012'));
});
