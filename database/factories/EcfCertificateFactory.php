<?php

namespace Database\Factories;

use App\Models\EcfCertificate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<EcfCertificate>
 */
class EcfCertificateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Certificado de prueba',
            'subject' => 'CN=Prueba Gomez, O=Gomez International SRL, C=DO, serialNumber=131880738',
            'issuer' => 'CN=Autoridad de prueba, C=DO',
            'serial_number' => '0A1B2C3D4E5F',
            'rnc' => '131880738',
            'fingerprint_sha256' => hash('sha256', Str::random()),
            'valid_from' => now()->subYear(),
            'valid_to' => now()->addYear(),
            'p12_encrypted' => 'x',
            'password_encrypted' => 'x',
            'is_active' => false,
            'uploaded_by' => User::factory(),
        ];
    }
}
