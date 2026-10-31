<?php

namespace App\Services\Ecf;

use App\Enums\EcfStatus;
use App\Models\EcfCertificate;
use App\Models\EcfDocument;
use App\Support\Ecf\EcfDocumentData;
use App\Support\Ecf\EcfQrUrl;
use App\Support\Ecf\SecurityCode;
use App\Support\Ecf\SignedXml;
use Carbon\CarbonImmutable;
use DOMDocument;

/** Builds, signs, validates and stores the XML of a document that the ERP signs itself. */
class LocalXmlPreparer
{
    private const XMLDSIG_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';

    public function __construct(
        private readonly EcfXmlWriter $writer,
        private readonly XmlSigner $signer,
        private readonly XsdValidator $validator,
        private readonly EcfStorage $storage,
    ) {}

    /**
     * Without a certificate a pseudo signature is appended (development and tests only); the XSD still requires a Signature element.
     */
    public function prepare(EcfDocument $document, EcfDocumentData $data, ?EcfCertificate $certificate): void
    {
        $fechaHoraFirma = CarbonImmutable::now();
        $signed = $this->sign($this->writer->write($data, $fechaHoraFirma), $certificate);
        $this->validator->validate($this->load($signed->xml), $this->validator->schemaFor($data->type));

        $rfce = null;
        if ($document->flow === 'rfce') {
            $rfce = $this->sign($this->writer->writeRfce($data, $signed->securityCode), $certificate);
            $this->validator->validate($this->load($rfce->xml), 'rfce');
        }

        $document->xml_path = $this->storage->putForDocument($document, $data->fileName(), $signed->xml);
        $document->security_code = $signed->securityCode;
        $document->signed_at = $fechaHoraFirma;

        $environment = $document->environment;
        if ($rfce !== null) {
            $document->rfce_xml_path = $this->storage->putForDocument($document, "RFCE-{$data->fileName()}", $rfce->xml);
            $document->qr_url = EcfQrUrl::forRfce($environment, $data->emisor->rnc, $data->encf, $data->totals->montoTotal, $signed->securityCode);
        } else {
            $document->qr_url = EcfQrUrl::forEcf(
                $environment,
                $data->emisor->rnc,
                $data->comprador->rnc !== '' ? $data->comprador->rnc : null,
                $data->encf,
                $data->fechaEmision,
                $data->totals->montoTotal,
                $fechaHoraFirma,
                $signed->securityCode,
            );
        }

        $document->status = EcfStatus::Signed;
    }

    private function sign(DOMDocument $unsigned, ?EcfCertificate $certificate): SignedXml
    {
        if ($certificate !== null) {
            return $this->signer->sign($unsigned, $certificate);
        }

        $signature = $unsigned->createElementNS(self::XMLDSIG_NAMESPACE, 'Signature');
        $signatureValue = $unsigned->createElementNS(self::XMLDSIG_NAMESPACE, 'SignatureValue', base64_encode(random_bytes(48)));
        $signature->appendChild($signatureValue);
        $unsigned->documentElement->appendChild($signature);

        $xml = (string) $unsigned->saveXML();

        return new SignedXml(
            xml: $xml,
            signatureValue: (string) $signatureValue->textContent,
            securityCode: SecurityCode::fromSignedXml($xml),
            signedAt: CarbonImmutable::now(),
        );
    }

    private function load(string $xml): DOMDocument
    {
        $document = new DOMDocument;
        $document->loadXML($xml);

        return $document;
    }
}
