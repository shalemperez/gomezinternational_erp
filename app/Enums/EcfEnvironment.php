<?php

namespace App\Enums;

enum EcfEnvironment: string
{
    case Test = 'test';
    case Cert = 'cert';
    case Prod = 'prod';

    public function label(): string
    {
        return match ($this) {
            self::Test => 'Pruebas (TesteCF)', self::Cert => 'Certificación (CerteCF)', self::Prod => 'Producción (eCF)',
        };
    }

    /** Segment of the DGII web service paths (`https://ecf.dgii.gov.do/{segment}/...`). */
    public function dgiiSegment(): string
    {
        return match ($this) {
            self::Test => 'testecf', self::Cert => 'certecf', self::Prod => 'ecf',
        };
    }
}
