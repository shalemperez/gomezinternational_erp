<?php

namespace App\Services\Ecf;

use App\Enums\NcfType;
use App\Exceptions\EcfValidationException;
use DOMDocument;
use InvalidArgumentException;
use LibXMLError;

class XsdValidator
{
    private const FILES = [
        'ecf-31' => 'e-CF 31 v.1.0.xsd', 'ecf-32' => 'e-CF 32 v.1.0.xsd', 'ecf-33' => 'e-CF 33 v.1.0.xsd', 'ecf-34' => 'e-CF 34 v.1.0.xsd',
        'ecf-41' => 'e-CF 41 v.1.0.xsd', 'ecf-43' => 'e-CF 43 v.1.0.xsd', 'ecf-44' => 'e-CF 44 v.1.0.xsd', 'ecf-45' => 'e-CF 45 v.1.0.xsd',
        'ecf-46' => 'e-CF 46 v.1.0.xsd', 'ecf-47' => 'e-CF 47 v.1.0.xsd', 'rfce' => 'RFCE 32 v.1.0.xsd', 'arecf' => 'ARECF v1.0.xsd',
        'acecf' => 'ACECF v.1.0.xsd', 'anecf' => 'ANECF v.1.0.xsd', 'semilla' => 'Semilla v.1.0.xsd',
    ];

    /** @throws EcfValidationException when the document does not satisfy the official schema */
    public function validate(DOMDocument $document, string $schema): void
    {
        if (! isset(self::FILES[$schema])) {
            throw new InvalidArgumentException("Unknown XSD schema [{$schema}].");
        }

        $file = self::FILES[$schema];
        $previous = libxml_use_internal_errors(true);
        try {
            libxml_clear_errors();
            $valid = $document->schemaValidate(config('ecf.xsd_path').DIRECTORY_SEPARATOR.$file);
            $errors = libxml_get_errors();
        } catch (\Throwable $e) {
            throw new EcfValidationException('El XSD '.$file.' no se pudo cargar: '.$e->getMessage(), 0, $e);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($valid) {
            return;
        }

        $messages = array_map(fn (LibXMLError $error): string => trim($error->message), array_slice($errors, 0, 5));

        throw new EcfValidationException('El XML no cumple el XSD '.$file.': '.implode(' | ', $messages));
    }

    public function schemaFor(NcfType $type): string
    {
        return 'ecf-'.$type->ecfCode();
    }
}
