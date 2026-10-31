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
        return $this->signWithPem($document, $this->certificates->certificatePem($certificate), $this->certificates->privateKeyPem($certificate));
    }

    /** Signs with a PEM certificate and private key (the company certificate via sign(), or any other pair). */
    public function signWithPem(DOMDocument $document, string $certPem, string $keyPem): SignedXml
    {
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        $dsig = new XMLSecurityDSig('');
        $dsig->setCanonicalMethod(XMLSecurityDSig::C14N);
        $dsig->addReference($document, XMLSecurityDSig::SHA256, [self::ENVELOPED_SIGNATURE], ['force_uri' => true]);

        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $key->loadKey($keyPem);
        $dsig->sign($key);
        $dsig->add509Cert($certPem);
        $dsig->appendSignature($document->documentElement);

        $xml = (string) $document->saveXML();

        return new SignedXml(
            xml: $xml,
            signatureValue: trim((string) $document->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'SignatureValue')->item(0)?->textContent),
            securityCode: SecurityCode::fromSignedXml($xml),
            signedAt: CarbonImmutable::now(),
        );
    }

    /**
     * The digits of the subject serialNumber of the first signing certificate (the taxpayer RNC or cédula), or null when
     * the certificate is missing or unreadable. Never throws.
     */
    public function signerTaxId(DOMDocument $document): ?string
    {
        try {
            $certificate = $document->getElementsByTagNameNS(XMLSecurityDSig::XMLDSIGNS, 'X509Certificate')->item(0)?->textContent;
            if ($certificate === null || trim($certificate) === '') {
                return null;
            }

            $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split((string) preg_replace('/\s+/', '', $certificate), 64, "\n")."-----END CERTIFICATE-----\n";
            $parsed = openssl_x509_parse($pem);
            if ($parsed === false) {
                return null;
            }

            $serial = $parsed['subject']['serialNumber'] ?? null;
            if (is_array($serial)) {
                $serial = $serial[0] ?? null;
            }

            $digits = preg_replace('/\D+/', '', (string) $serial) ?? '';

            return $digits === '' ? null : $digits;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Returns false for a missing signature, a changed reference or a bad signature; never throws. Verifies a copy because
     * validateReference() detaches the Signature from the document it is given.
     */
    public function verify(DOMDocument $document): bool
    {
        try {
            /** @var DOMDocument $copy */
            $copy = $document->cloneNode(true);
            $dsig = new XMLSecurityDSig('');
            $signatureNode = $dsig->locateSignature($copy);
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
