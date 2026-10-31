<?php

use App\Enums\NcfType;
use App\Exceptions\EcfValidationException;
use App\Models\Client;
use App\Services\Ecf\EcfDocumentBuilder;
use App\Services\Ecf\EcfXmlWriter;
use App\Services\Ecf\XmlSigner;
use App\Services\Ecf\XsdValidator;
use Database\Seeders\DocumentSequenceSeeder;

beforeEach(function () {
    (new DocumentSequenceSeeder)->run();
});

test('the rfce summary mirrors the consumo totals and carries the security code', function () {
    companyFiscalSettings();
    $client = Client::factory()->create(['tax_id' => null, 'tax_id_type' => 'none', 'taxpayer_type' => 'consumidor_final']);
    $invoice = issuedInvoice(['client_id' => $client->id]);
    $data = app(EcfDocumentBuilder::class)->fromInvoice($invoice, NcfType::E32, 'E320000000009', null);
    $signed = app(XmlSigner::class)->sign(app(EcfXmlWriter::class)->writeRfce($data, 'aB12cd'), activeCertificate());
    $doc = new DOMDocument;
    $doc->loadXML($signed->xml);
    app(XsdValidator::class)->validate($doc, 'rfce');
    $x = new DOMXPath($doc);
    expect($x->evaluate('string(/RFCE/Encabezado/IdDoc/TipoeCF)'))->toBe('32')->and($x->evaluate('string(//IdDoc/eNCF)'))->toBe('E320000000009')
        ->and($x->evaluate('string(//Emisor/RNCEmisor)'))->toBe('131880738')->and($x->evaluate('string(//Comprador/RazonSocialComprador)'))->toBe($invoice->client_name)
        ->and($x->evaluate('string(//Totales/MontoTotal)'))->toBe('236.00')->and($x->evaluate('string(//Encabezado/CodigoSeguridadeCF)'))->toBe('aB12cd')
        ->and($x->query('//DetallesItems')->length)->toBe(0);
});

test('xsd validation reports the schema file and the libxml message', function () {
    $doc = new DOMDocument;
    $doc->loadXML('<ECF><Encabezado><Version>1.0</Version></Encabezado></ECF>');
    expect(fn () => app(XsdValidator::class)->validate($doc, 'ecf-31'))->toThrow(EcfValidationException::class, 'El XML no cumple el XSD e-CF 31 v.1.0.xsd:');
    expect(fn () => app(XsdValidator::class)->validate($doc, 'nope'))->toThrow(InvalidArgumentException::class);
});

test('a schema that cannot be compiled raises a validation exception and restores the libxml error setting', function () {
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ecf-broken-xsd-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.DIRECTORY_SEPARATOR.'e-CF 31 v.1.0.xsd', '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="ECF" type="Nope"/></xs:schema>');
    config(['ecf.xsd_path' => $directory]);
    $doc = new DOMDocument;
    $doc->loadXML('<ECF/>');

    libxml_use_internal_errors(false);
    expect(fn () => app(XsdValidator::class)->validate($doc, 'ecf-31'))->toThrow(EcfValidationException::class);
    expect(libxml_use_internal_errors(false))->toBeFalse();

    unlink($directory.DIRECTORY_SEPARATOR.'e-CF 31 v.1.0.xsd');
    rmdir($directory);
});
