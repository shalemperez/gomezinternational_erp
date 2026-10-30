<?php

use App\Jobs\SubmitEcfDocument;
use App\Models\EcfCertificate;
use App\Models\EcfDocument;
use App\Models\ReceivedEcf;
use App\Models\Supplier;
use App\Services\Ecf\EcfXmlWriter;
use App\Services\Ecf\XmlSigner;
use App\Services\Ecf\XsdValidator;
use App\Services\Settings;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    (new DocumentSequenceSeeder)->run();
    ecfMode('dgii');
    activeCertificate();
});

/** Writes an ACECF about one of our e-CF and signs it with a fake supplier certificate (signature only, not trust). */
function signedAcecfFor(EcfDocument $document, int $estado, ?string $motivo): string
{
    openssl_pkcs12_read(fakeP12('x', '101010101'), $bundle, 'x');
    $acecf = app(EcfXmlWriter::class)->writeAcecf('131880738', $document->encf, CarbonImmutable::now(), '1180.00', '101010101', $estado, $motivo, CarbonImmutable::now());

    return app(XmlSigner::class)->signWithPem($acecf, $bundle['cert'], $bundle['pkey'])->xml;
}

test('a valid supplier e-cf is stored, acknowledged with a signed arecf and linked to the supplier', function () {
    $supplier = Supplier::factory()->create(['tax_id' => '101010101', 'country_code' => 'DO']);
    $response = $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('101010101E310000000777.xml', supplierEcfXml())]);
    $response->assertOk()->assertHeader('content-type', 'application/xml');
    $arecf = new DOMDocument;
    $arecf->loadXML($response->getContent());
    app(XsdValidator::class)->validate($arecf, 'arecf');
    $x = new DOMXPath($arecf);
    expect($x->evaluate('string(//DetalleAcusedeRecibo/Estado)'))->toBe('0')->and($x->evaluate('string(//DetalleAcusedeRecibo/eNCF)'))->toBe('E310000000777')->and($x->evaluate('string(//DetalleAcusedeRecibo/RNCComprador)'))->toBe('131880738')
        ->and(app(XmlSigner::class)->verify($arecf))->toBeTrue();
    $received = ReceivedEcf::query()->firstOrFail();
    expect($received->rnc_emisor)->toBe('101010101')->and($received->supplier_id)->toBe($supplier->id)->and($received->monto_total)->toBe('1180.00')->and($received->arecf_status)->toBe(0)
        ->and(Storage::disk('local')->exists($received->xml_path))->toBeTrue()->and(Storage::disk('local')->exists($received->arecf_path))->toBeTrue()
        ->and(Activity::inLog('received_ecfs')->where('event', 'received')->where('subject_id', $received->id)->exists())->toBeTrue();
});

test('the reception endpoint answers a signed arecf with the motivo code for invalid xsd, bad signature, duplicate and wrong buyer rnc', function () {
    $valid = supplierEcfXml();
    $cases = [
        [str_replace('<TipoIngresos>01</TipoIngresos>', '', $valid), 1, 'xsd'],
        [str_replace('Proveedor Prueba SRL', 'Proveedor Alterado SRL', $valid), 2, 'signature'],
        [supplierEcfXml(['comprador_rnc' => '999999999']), 4, 'buyer'],
    ];
    foreach ($cases as [$xml, $expected, $label]) {
        $response = $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', $xml)])->assertOk();
        $x = new DOMXPath(tap(new DOMDocument, fn ($d) => $d->loadXML($response->getContent())));
        expect($x->evaluate('string(//Estado)'))->toBe('1', $label)->and($x->evaluate('string(//CodigoMotivoNoRecibido)'))->toBe((string) $expected, $label);
    }
    $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', $valid)])->assertOk();
    $dup = $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', $valid)])->assertOk();
    expect((new DOMXPath(tap(new DOMDocument, fn ($d) => $d->loadXML($dup->getContent()))))->evaluate('string(//CodigoMotivoNoRecibido)'))->toBe('3');
    $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', 'not xml at all')])->assertOk();
    $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><ECF>&e;</ECF>')])->assertOk();
    expect(ReceivedEcf::count())->toBe(4)->and(ReceivedEcf::query()->where('arecf_status', 0)->count())->toBe(1); // 3 refused rows (xsd, signature, buyer) + the valid one; the duplicate is not stored again
});

test('paths are case-insensitive and the services answer 503 when not in dgii mode or without certificate', function () {
    $this->post('/FE/Recepcion/API/ECF', ['xml' => UploadedFile::fake()->createWithContent('x.xml', supplierEcfXml(['encf' => 'E310000000778']))])->assertOk();
    EcfCertificate::query()->update(['is_active' => false]);
    $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', supplierEcfXml())])->assertStatus(503);
    activeCertificate();
    app(Settings::class)->set('ecf.provider', 'fake');
    $this->post('/fe/aprobacioncomercial/api/ecf', ['xml' => 'x'])->assertStatus(503);
});

test('an incoming commercial approval updates our e-cf document and an unknown one is refused', function () {
    Bus::fake([SubmitEcfDocument::class]);
    $invoice = issuedInvoice();
    $doc = $invoice->ecfDocument;
    $acecf = signedAcecfFor($doc, 2, 'Precio incorrecto');
    $this->post('/fe/aprobacioncomercial/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', $acecf)])->assertOk();
    expect($doc->fresh()->commercial_status)->toBe('rejected')->and($doc->fresh()->commercial_reason)->toBe('Precio incorrecto')->and($doc->transmissions()->where('kind', 'approval_in')->count())->toBe(1)
        ->and(Activity::inLog('ecf_documents')->where('subject_id', $doc->id)->where('event', 'commercial_approval_received')->exists())->toBeTrue();
    $this->post('/fe/aprobacioncomercial/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', str_replace($doc->encf, 'E310000009999', $acecf))])->assertStatus(400);
});

test('the receiver answers 503 while the active certificate is expired', function () {
    EcfCertificate::query()->update(['valid_to' => now()->subDay()]);
    $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', supplierEcfXml())])->assertStatus(503);
    expect(ReceivedEcf::count())->toBe(0);
});

test('a refused document with a bad fecha emision or an oversized total is answered with an arecf and stored with its identity only', function () {
    $badDate = preg_replace('/<FechaEmision>[^<]*<\/FechaEmision>/', '<FechaEmision>2026-10-30</FechaEmision>', supplierEcfXml(['encf' => 'E310000000781']), 1);
    $hugeTotal = preg_replace('/<MontoTotal>[^<]*<\/MontoTotal>/', '<MontoTotal>99999999999999999.99</MontoTotal>', supplierEcfXml(['encf' => 'E310000000782']), 1);
    foreach ([$badDate, $hugeTotal] as $xml) {
        $response = $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', $xml)])->assertOk()->assertHeader('content-type', 'application/xml');
        $x = new DOMXPath(tap(new DOMDocument, fn ($d) => $d->loadXML($response->getContent())));
        expect($x->evaluate('string(//Estado)'))->toBe('1');
    }
    $rows = ReceivedEcf::query()->orderBy('encf')->get();
    expect($rows)->toHaveCount(2)->and($rows->first()->arecf_reason_code)->toBe(1)
        ->and($rows->pluck('fecha_emision')->filter()->all())->toBe([])->and($rows->pluck('rnc_comprador')->filter()->all())->toBe([])
        ->and($rows->pluck('monto_total')->all())->toBe(['0.00', '0.00']);
});

test('a storage failure while saving a receipt still answers the arecf', function () {
    Storage::disk('local')->put('ecf/received/'.now()->year.'/101010101E310000000780.xml', 'stale');
    $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', supplierEcfXml(['encf' => 'E310000000780']))])
        ->assertOk()->assertHeader('content-type', 'application/xml');
    expect(ReceivedEcf::count())->toBe(0);
});

test('a utf-16 document with a doctype is refused after parsing without being stored', function () {
    $utf8 = preg_replace('/^<\?xml[^>]*\?>/', '<?xml version="1.0" encoding="UTF-16"?><!DOCTYPE ECF>', supplierEcfXml(['encf' => 'E310000000783']), 1);
    $response = $this->post('/fe/recepcion/api/ecf', ['xml' => UploadedFile::fake()->createWithContent('x.xml', mb_convert_encoding($utf8, 'UTF-16', 'UTF-8'))])->assertOk();
    $x = new DOMXPath(tap(new DOMDocument, fn ($d) => $d->loadXML($response->getContent())));
    expect($x->evaluate('string(//Estado)'))->toBe('1')->and($x->evaluate('string(//CodigoMotivoNoRecibido)'))->toBe('1')->and(ReceivedEcf::count())->toBe(0);
});
