<?php

namespace App\Support\Ecf;

use App\Exceptions\EcfException;
use DOMDocument;

final class SecurityCode
{
    private const XMLDSIG_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';

    /** @throws EcfException when the xml has no SignatureValue */
    public static function fromSignedXml(string $xml): string
    {
        $document = new DOMDocument;
        $document->loadXML($xml);
        $signatureValue = $document->getElementsByTagNameNS(self::XMLDSIG_NAMESPACE, 'SignatureValue')->item(0);
        if ($signatureValue === null) {
            throw new EcfException('El XML no contiene SignatureValue');
        }

        $value = (string) preg_replace('/\s+/', '', (string) $signatureValue->textContent);

        return substr($value, 0, 6);
    }
}
