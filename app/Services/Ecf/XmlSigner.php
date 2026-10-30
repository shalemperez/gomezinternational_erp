<?php

namespace App\Services\Ecf;

use App\Models\EcfCertificate;
use App\Support\Ecf\SecurityCode;
use App\Support\Ecf\SignedXml;
use Carbon\CarbonImmutable;
use DOMDocument;
use RobRichards\XMLSecLibs\XMLSecEnc;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use Throwable;

class XmlSigner
{
    private const ENVELOPED_SIGNATURE = 'http://www.w3.org/2000/09/xmldsig#enveloped-signature';

    public function __construct(private readonly CertificateStore $certificates) {}

    /** Appends an enveloped XMLDSig signature (rsa-sha256, C14N, enveloped transform) as the last child of the root. */
    public function sign(DOMDocument $document, EcfCertificate $certificate): SignedXml
    {
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        $dsig = new XMLSecurityDSig('');
        $dsig->setCanonicalMethod(XMLSecurityDSig::C14N);
        $dsig->addReference($document, XMLSecurityDSig::SHA256, [self::ENVELOPED_SIGNATURE], ['force_uri' => true]);

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($this->certificates->privateKeyPem($certificate));
        $dsig->sign($key);
        $dsig->add509Cert($this->certificates->certificatePem($certificate));
        $dsig->appendSignature($document->documentElement);

        $xml = (string) $document->saveXML();

        return new SignedXml(
            xml: $xml,
            signatureValue: trim((string) $document->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'SignatureValue')->item(0)?->textContent),
            securityCode: SecurityCode::fromSignedXml($xml),
            signedAt: CarbonImmutable::now(),
        );
    }

    /** Returns false for a missing signature, a changed reference or a bad signature; never throws. */
    public function verify(DOMDocument $document): bool
    {
        try {
            $dsig = new XMLSecurityDSig('');
            $signatureNode = $dsig->locateSignature($document);
            if ($signatureNode === null) {
                return false;
            }

            $dsig->canonicalizeSignedInfo();
            if (! $dsig->validateReference()) {
                return false;
            }

            $key = $dsig->locateKey();
            if ($key === null) {
                return false;
            }

            XMLSecEnc::staticLocateKeyInfo($key, $signatureNode);

            return $dsig->verify($key) === 1;
        } catch (Throwable) {
            return false;
        }
    }
}
