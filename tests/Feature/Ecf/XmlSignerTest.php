<?php

use App\Exceptions\EcfException;
use App\Services\Ecf\XmlSigner;
use App\Support\Ecf\SecurityCode;

function sampleDom(): DOMDocument
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->preserveWhiteSpace = false;
    $doc->loadXML('<ECF><Encabezado><Version>1.0</Version></Encabezado><FechaHoraFirma>01-11-2026 10:00:00</FechaHoraFirma></ECF>');

    return $doc;
}

test('signing appends an enveloped rsa-sha256 signature with the dgii algorithms and the certificate', function () {
    $cert = activeCertificate();
    $signed = app(XmlSigner::class)->sign(sampleDom(), $cert);

    expect($signed->xml)->toContain('<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">')
        ->and($signed->xml)->not->toContain('ds:Signature')
        ->and($signed->xml)->toContain('Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"')
        ->and($signed->xml)->toContain('Algorithm="http://www.w3.org/2001/04/xmldsig-more#rsa-sha256"')
        ->and($signed->xml)->toContain('Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"')
        ->and($signed->xml)->toContain('Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"')
        ->and($signed->xml)->toContain('<Reference URI="">')
        ->and($signed->xml)->toContain('<X509Certificate>')
        ->and(strlen($signed->securityCode))->toBe(6)->and($signed->securityCode)->toBe(substr($signed->signatureValue, 0, 6))
        ->and(preg_match('/<\/FechaHoraFirma><Signature /', $signed->xml))->toBe(1);
});

test('verify accepts an untouched signed document and rejects a tampered one', function () {
    $cert = activeCertificate();
    $signed = app(XmlSigner::class)->sign(sampleDom(), $cert);
    $ok = new DOMDocument;
    $ok->loadXML($signed->xml);
    $tampered = new DOMDocument;
    $tampered->loadXML(str_replace('<Version>1.0</Version>', '<Version>2.0</Version>', $signed->xml));
    $unsigned = sampleDom();

    expect(app(XmlSigner::class)->verify($ok))->toBeTrue()
        ->and(app(XmlSigner::class)->verify($tampered))->toBeFalse()
        ->and(app(XmlSigner::class)->verify($unsigned))->toBeFalse();
});

test('the security code is the first six characters of the signature value', function () {
    $xml = '<ECF><Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignatureValue>AbC+12xyz==</SignatureValue></Signature></ECF>';
    expect(SecurityCode::fromSignedXml($xml))->toBe('AbC+12');
    expect(fn () => SecurityCode::fromSignedXml('<ECF/>'))->toThrow(EcfException::class, 'El XML no contiene SignatureValue');
});

test('a signature whose key info carries more than one certificate or a bare key is refused', function () {
    $signed = app(XmlSigner::class)->sign(sampleDom(), activeCertificate());
    $doc = new DOMDocument;
    $doc->loadXML($signed->xml);
    $x509 = $doc->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#', 'X509Certificate')->item(0);
    $x509->parentNode->appendChild($x509->cloneNode(true));

    expect(app(XmlSigner::class)->verify($doc))->toBeFalse()
        ->and(app(XmlSigner::class)->signerTaxId($doc))->toBeNull();
});

test('the signer tax id comes from the verifying certificate', function () {
    $cert = activeCertificate();
    $signed = app(XmlSigner::class)->sign(sampleDom(), $cert);
    $doc = new DOMDocument;
    $doc->loadXML($signed->xml);
    $unsigned = sampleDom();

    expect(app(XmlSigner::class)->signerTaxId($doc))->not->toBeNull()
        ->and(app(XmlSigner::class)->signerTaxId($unsigned))->toBeNull();
});
