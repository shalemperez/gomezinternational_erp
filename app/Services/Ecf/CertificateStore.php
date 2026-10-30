<?php

namespace App\Services\Ecf;

use App\Exceptions\EcfCertificateException;
use App\Models\EcfCertificate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CertificateStore
{
    private const UNREADABLE_MESSAGE = 'No se pudo leer el certificado .p12: contraseña incorrecta o formato no soportado (si fue exportado con cifrado heredado RC2, reexpórtalo con AES-256).';

    /** @throws EcfCertificateException when the .p12 cannot be read or the certificate is expired */
    public function store(string $p12Binary, string $password, User $actor, ?string $name = null): EcfCertificate
    {
        $bundle = [];
        if (! openssl_pkcs12_read($p12Binary, $bundle, $password)) {
            throw new EcfCertificateException(self::UNREADABLE_MESSAGE);
        }
        if (empty($bundle['pkey'])) {
            throw new EcfCertificateException('El archivo .p12 no contiene la llave privada.');
        }

        $parsed = $this->parseCertificate($bundle['cert']);
        $validTo = Carbon::createFromTimestampUTC($parsed['validTo_time_t']);
        if ($validTo->isPast()) {
            throw new EcfCertificateException('El certificado venció el '.$validTo->setTimezone('America/Santo_Domingo')->format('d/m/Y').'.');
        }

        $subject = $this->distinguishedName($parsed['subject']);

        return DB::transaction(function () use ($p12Binary, $password, $actor, $name, $parsed, $bundle, $subject, $validTo): EcfCertificate {
            $fingerprint = openssl_x509_fingerprint($bundle['cert'], 'sha256');
            if (EcfCertificate::query()->where('fingerprint_sha256', $fingerprint)->exists()) {
                throw new EcfCertificateException('Este certificado ya fue cargado.');
            }

            $certificate = EcfCertificate::query()->create([
                'name' => $name ?? Str::limit($subject, 100, ''),
                'subject' => $subject,
                'issuer' => $this->distinguishedName($parsed['issuer']),
                'serial_number' => $parsed['serialNumberHex'] ?? $parsed['serialNumber'],
                'rnc' => preg_replace('/\D/', '', $this->attributeValue($parsed['subject']['serialNumber'] ?? '')) ?: null,
                'fingerprint_sha256' => $fingerprint,
                'valid_from' => Carbon::createFromTimestampUTC($parsed['validFrom_time_t']),
                'valid_to' => $validTo,
                'p12_encrypted' => $p12Binary,
                'password_encrypted' => $password,
                'is_active' => false,
                'uploaded_by' => $actor->id,
            ]);

            activity('ecf_certificates')->causedBy($actor)->performedOn($certificate)->event('uploaded')
                ->withProperties(['subject' => $certificate->subject, 'serial_number' => $certificate->serial_number, 'valid_to' => $validTo->toIso8601String()])
                ->log('Certificado digital cargado');

            $this->activate($certificate, $actor);

            return $certificate;
        });
    }

    public function active(): ?EcfCertificate
    {
        return EcfCertificate::query()->where('is_active', true)->latest('id')->first();
    }

    /** @throws EcfCertificateException */
    public function requireActive(): EcfCertificate
    {
        return $this->active() ?? throw new EcfCertificateException('No hay un certificado digital activo; cárgalo en Configuración → Facturación electrónica.');
    }

    public function privateKeyPem(EcfCertificate $certificate): string
    {
        return $this->readBundle($certificate)['pkey'];
    }

    public function certificatePem(EcfCertificate $certificate): string
    {
        return $this->readBundle($certificate)['cert'];
    }

    public function deactivate(EcfCertificate $certificate, User $actor): void
    {
        if (! $certificate->is_active) {
            return;
        }

        $certificate->update(['is_active' => false]);

        activity('ecf_certificates')->causedBy($actor)->performedOn($certificate)->event('deactivated')->log('Certificado desactivado');
    }

    private function activate(EcfCertificate $certificate, User $actor): void
    {
        EcfCertificate::query()->where('is_active', true)->whereKeyNot($certificate->getKey())->lockForUpdate()->get()
            ->each(fn (EcfCertificate $previous) => $this->deactivate($previous, $actor));

        $certificate->update(['is_active' => true]);

        activity('ecf_certificates')->causedBy($actor)->performedOn($certificate)->event('activated')->log('Certificado activado');
    }

    /** @return array{cert: string, pkey: string} */
    private function readBundle(EcfCertificate $certificate): array
    {
        $bundle = [];
        if (! openssl_pkcs12_read($certificate->p12_encrypted, $bundle, $certificate->password_encrypted)) {
            throw new EcfCertificateException('No se pudo leer el certificado .p12 guardado.');
        }

        return ['cert' => $bundle['cert'], 'pkey' => $bundle['pkey']];
    }

    /** @return array<string, mixed> */
    private function parseCertificate(string $certificatePem): array
    {
        $parsed = openssl_x509_parse($certificatePem);
        if ($parsed === false) {
            throw new EcfCertificateException(self::UNREADABLE_MESSAGE);
        }

        return $parsed;
    }

    /** @param  array<string, string|array<int|string, string>>  $parts */
    private function distinguishedName(array $parts): string
    {
        return collect($parts)->map(fn (string|array $value, string $key) => "{$key}=".$this->attributeValue($value))->implode(', ');
    }

    /** A DN attribute repeated in the certificate comes back as a list; its values are joined. */
    private function attributeValue(mixed $value): string
    {
        return is_array($value) ? implode(', ', $value) : (string) $value;
    }
}
