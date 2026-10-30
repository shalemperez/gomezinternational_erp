<?php

use App\Enums\EcfStatus;
use App\Enums\NcfType;
use App\Exceptions\EcfCertificateException;
use App\Exceptions\EcfException;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    (new DocumentSequenceSeeder)->run();
    ecfSettings('dgii');
    activeCertificate();
});

/** The DGII seed and token endpoints of the test environment. */
function fakeAuth(): array
{
    return [
        'https://ecf.dgii.gov.do/testecf/autenticacion/api/autenticacion/semilla' => Http::response('<?xml version="1.0"?><SemillaModel><valor>abc-123</valor><fecha>2026-11-01T10:00:00</fecha></SemillaModel>', 200, ['Content-Type' => 'application/xml']),
        'https://ecf.dgii.gov.do/testecf/autenticacion/api/autenticacion/validarsemilla' => Http::response(['token' => 'tok-1', 'expira' => '2026-11-01T11:00:00Z', 'expedido' => '2026-11-01T10:00:00Z']),
    ];
}

function electronicSequenceId(string $type): int
{
    return (int) NcfSequence::query()->where('ncf_type', $type)->value('id');
}

test('authentication fetches the seed, signs it and caches the bearer token', function () {
    Http::fake(fakeAuth());
    $client = app(DgiiClient::class);
    expect($client->token())->toBe('tok-1')->and($client->token())->toBe('tok-1');
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/validarsemilla') && $r->hasFile('xml') && str_contains((string) $r->body(), '<SignatureValue>') && str_contains((string) $r->body(), '<valor>abc-123</valor>'));
});

test('an e-cf submission posts the signed xml as multipart with the standard file name and stores the trackid', function () {
    Http::fake([...fakeAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::response(['trackId' => 'TRK-1', 'error' => null, 'mensaje' => null])]);
    $service = app(EcfService::class);
    $doc = $service->create(issuedInvoice(), new FiscalNumber('E310000000001', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null);
    $doc = $service->submit($doc);
    expect($doc->status)->toBe(EcfStatus::Sent)->and($doc->track_id)->toBe('TRK-1')->and($doc->provider)->toBe('dgii')
        ->and($doc->transmissions()->where('kind', 'send')->value('http_status'))->toBe(200);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/recepcion/api/facturaselectronicas') && $r->hasHeader('Authorization', 'Bearer tok-1') && $r->hasFile('xml', null, '131880738E310000000001.xml'));
});

test('rfce submissions post the summary to recepcionfc and apply the synchronous result', function () {
    Http::fake([...fakeAuth(), 'https://fc.dgii.gov.do/testecf/recepcionfc/api/recepcion/ecf' => Http::response(['codigo' => 1, 'estado' => 'Aceptado', 'mensajes' => [], 'encf' => 'E320000000001', 'secuenciaUtilizada' => true])]);
    $client = Client::factory()->create(['tax_id' => null, 'tax_id_type' => 'none', 'taxpayer_type' => 'consumidor_final']);
    $service = app(EcfService::class);
    $doc = $service->submit($service->create(issuedInvoice(['client_id' => $client->id]), new FiscalNumber('E320000000001', NcfType::E32, null, electronicSequenceId('E32')), null));
    expect($doc->status)->toBe(EcfStatus::Accepted)->and($doc->flow)->toBe('rfce')->and($doc->track_id)->toBeNull()->and($doc->dgii_code)->toBe(1);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'fc.dgii.gov.do/testecf/recepcionfc') && $r->hasFile('xml', null, 'RFCE-131880738E320000000001.xml') && str_contains((string) $r->body(), '<RFCE>'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/recepcion/api/facturaselectronicas'));
});

test('consumo invoices at or above the threshold are sent as full e-cf', function () {
    Http::fake([...fakeAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::response(['trackId' => 'TRK-2'])]);
    $client = Client::factory()->create(['tax_id' => '00112345678', 'tax_id_type' => 'cedula', 'taxpayer_type' => 'consumidor_final', 'credit_limit' => '0']);
    $invoice = issuedInvoice(['client_id' => $client->id, 'lines' => [['product_id' => Product::factory()->create(['itbis_rate' => '18.00'])->id, 'description' => '', 'quantity' => '1000', 'unit_price' => '250', 'discount_percent' => '0']]]);
    $service = app(EcfService::class);
    $doc = $service->submit($service->create($invoice, new FiscalNumber('E320000000002', NcfType::E32, null, electronicSequenceId('E32')), null));
    expect($invoice->total)->toBe('295000.00')->and($doc->flow)->toBe('ecf')->and($doc->status)->toBe(EcfStatus::Sent)->and($doc->qr_url)->toContain('/consultatimbre?');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'recepcionfc'));
});

test('polling maps dgii codes to statuses and stores messages', function () {
    Http::fake([...fakeAuth(), 'https://ecf.dgii.gov.do/testecf/consultaresultado/api/consultas/estado?trackid=TRK-A' => Http::response(['trackId' => 'TRK-A', 'codigo' => 4, 'estado' => 'Aceptado Condicional', 'rnc' => '131880738', 'eNCF' => 'E310000000001', 'secuenciaUtilizada' => true, 'fechaRecepcion' => '01-11-2026 10:00:00', 'mensajes' => [['valor' => 'Advertencia X', 'codigo' => 120]]]),
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
    Http::fake([...fakeAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::response(['trackId' => null, 'error' => 'XSD', 'mensaje' => 'La estructura del archivo XML no es válido, favor proveer un XML con una estructura válida'], 400)]);
    $service = app(EcfService::class);
    $doc = $service->submit($service->create(issuedInvoice(), new FiscalNumber('E310000000003', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null));
    expect($doc->status)->toBe(EcfStatus::Rejected)->and($doc->dgii_messages[0]['valor'])->toContain('estructura')->and($doc->transmissions()->first()->http_status)->toBe(400);
});

test('an expired token is refreshed once on 401', function () {
    Http::fake([...fakeAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => Http::sequence()->push([], 401)->push(['trackId' => 'TRK-R'], 200)]);
    $service = app(EcfService::class);
    $doc = $service->submit($service->create(issuedInvoice(), new FiscalNumber('E310000000004', NcfType::E31, CarbonImmutable::parse('2027-12-31'), electronicSequenceId('E31')), null));
    expect($doc->status)->toBe(EcfStatus::Sent)->and($doc->track_id)->toBe('TRK-R');
    Http::assertSentCount(6);
});

test('a network failure surfaces as an ecf exception naming the dgii', function () {
    Http::fake([...fakeAuth(), 'https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas' => fn () => throw new ConnectionException('cURL error 28: timeout')]);
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
    Http::fake([...fakeAuth(), 'https://ecf.dgii.gov.do/testecf/consultadirectorio/api/consultas/obtenerdirectorioporrnc?RNC=101010101' => Http::response([['nombre' => 'Proveedor SRL', 'rnc' => '101010101', 'urlRecepcion' => 'https://prov.test/fe', 'urlAceptacion' => 'https://prov.test/fe', 'urlOpcional' => null]]),
        'https://ecf.dgii.gov.do/testecf/consultadirectorio/api/consultas/obtenerdirectorioporrnc?RNC=999999999' => Http::response([])]);
    $client = app(DgiiClient::class);
    expect($client->directoryByRnc('101010101')['urlAceptacion'])->toBe('https://prov.test/fe')->and($client->directoryByRnc('999999999'))->toBeNull();
});
