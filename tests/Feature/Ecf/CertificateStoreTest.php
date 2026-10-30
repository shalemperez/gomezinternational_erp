<?php

use App\Exceptions\EcfCertificateException;
use App\Models\EcfCertificate;
use App\Models\User;
use App\Services\Ecf\CertificateStore;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

test('storing a p12 parses subject, serial, validity and rnc, encrypts the secrets and activates it', function () {
    $actor = User::factory()->create();
    $cert = app(CertificateStore::class)->store(fakeP12('secret', '131880738'), 'secret', $actor, 'Certificado 2026');

    expect($cert->is_active)->toBeTrue()->and($cert->rnc)->toBe('131880738')->and($cert->subject)->toContain('Prueba Gomez')
        ->and(strlen($cert->fingerprint_sha256))->toBe(64)->and($cert->valid_to->isFuture())->toBeTrue()
        ->and($cert->getRawOriginal('p12_encrypted'))->not->toContain('MII')
        ->and(app(CertificateStore::class)->privateKeyPem($cert))->toContain('PRIVATE KEY')
        ->and(app(CertificateStore::class)->certificatePem($cert))->toContain('BEGIN CERTIFICATE')
        ->and(Activity::inLog('ecf_certificates')->where('event', 'uploaded')->where('subject_id', $cert->id)->exists())->toBeTrue();
    $logged = json_encode(Activity::inLog('ecf_certificates')->where('subject_id', $cert->id)->get()->pluck('properties'));
    expect($logged)->not->toContain('p12_encrypted')->and($logged)->not->toContain('secret');
});

test('a wrong password or an expired certificate is refused with a spanish message', function () {
    $actor = User::factory()->create();
    expect(fn () => app(CertificateStore::class)->store(fakeP12('secret'), 'otra', $actor))
        ->toThrow(EcfCertificateException::class, 'No se pudo leer el certificado .p12: contraseña incorrecta o formato no soportado (si fue exportado con cifrado heredado RC2, reexpórtalo con AES-256).');
    $expiringP12 = fakeP12('secret', '131880738', 1);
    $this->travel(2)->days();
    expect(fn () => app(CertificateStore::class)->store($expiringP12, 'secret', $actor))
        ->toThrow(EcfCertificateException::class, 'El certificado venció el');
    expect(EcfCertificate::count())->toBe(0);
});

test('storing a second certificate deactivates the previous one and requireActive resolves the newest', function () {
    $first = activeCertificate();
    $second = activeCertificate();
    expect($first->fresh()->is_active)->toBeFalse()->and($second->fresh()->is_active)->toBeTrue()
        ->and(app(CertificateStore::class)->requireActive()->id)->toBe($second->id)
        ->and(Activity::inLog('ecf_certificates')->where('event', 'deactivated')->where('subject_id', $first->id)->exists())->toBeTrue();
});

test('requireActive throws when no certificate is active', function () {
    expect(fn () => app(CertificateStore::class)->requireActive())
        ->toThrow(EcfCertificateException::class, 'No hay un certificado digital activo; cárgalo en Configuración → Facturación electrónica.');
});

/** Builds a .p12 with the CLI so the bundle can lack a private key or repeat a DN attribute (PHP's exporter cannot). */
function certificateP12FromSubject(string $subject, bool $withPrivateKey, string $password = 'secret'): string
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p12-'.uniqid();
    mkdir($directory);
    $certificatePath = $directory.'/cert.pem';
    $keyPath = $directory.'/key.pem';
    $p12Path = $directory.'/bundle.p12';

    Process::run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-keyout', $keyPath, '-out', $certificatePath, '-days', '365', '-subj', $subject])->throw();
    $exportArguments = ['openssl', 'pkcs12', '-export', '-in', $certificatePath, '-out', $p12Path, '-passout', 'pass:'.$password];
    $exportArguments = $withPrivateKey ? [...$exportArguments, '-inkey', $keyPath] : [...$exportArguments, '-nokeys'];
    Process::run($exportArguments)->throw();

    $p12 = file_get_contents($p12Path);
    array_map('unlink', glob($directory.'/*') ?: []);
    rmdir($directory);

    return $p12;
}

test('a p12 without a private key is refused with a spanish message', function () {
    $p12 = certificateP12FromSubject('/CN=Sin llave/serialNumber=131880738', withPrivateKey: false);
    expect(fn () => app(CertificateStore::class)->store($p12, 'secret', User::factory()->create()))
        ->toThrow(EcfCertificateException::class, 'El archivo .p12 no contiene la llave privada.');
    expect(EcfCertificate::count())->toBe(0);
});

test('storing the same certificate twice is refused with a spanish message instead of a unique violation', function () {
    $actor = User::factory()->create();
    $p12 = fakeP12('secret');
    app(CertificateStore::class)->store($p12, 'secret', $actor);
    expect(fn () => app(CertificateStore::class)->store($p12, 'secret', $actor))
        ->toThrow(EcfCertificateException::class, 'Este certificado ya fue cargado.');
    expect(EcfCertificate::count())->toBe(1);
});

test('a repeated subject attribute is joined instead of breaking the subject and rnc', function () {
    $p12 = certificateP12FromSubject('/C=DO/OU=Uno/OU=Dos/CN=Multi/serialNumber=131880738', withPrivateKey: true);
    $cert = app(CertificateStore::class)->store($p12, 'secret', User::factory()->create());
    expect($cert->subject)->toContain('OU=Uno, Dos')->and($cert->rnc)->toBe('131880738');
});

test('deactivating an inactive certificate writes no activity row', function () {
    $actor = User::factory()->create();
    $cert = activeCertificate($actor);
    $store = app(CertificateStore::class);
    $store->deactivate($cert, $actor);
    $store->deactivate($cert->fresh(), $actor);
    expect(Activity::inLog('ecf_certificates')->where('event', 'deactivated')->where('subject_id', $cert->id)->count())->toBe(1);
});

test('an expired active certificate is refused for signing but still reported as current', function () {
    $certificate = activeCertificate();
    $certificate->forceFill(['valid_to' => now()->subDay()])->save();
    $store = app(CertificateStore::class);
    $expected = 'El certificado digital venció el '.$certificate->fresh()->valid_to->setTimezone('America/Santo_Domingo')->format('d/m/Y').'.';
    expect(fn () => $store->active())->toThrow(EcfCertificateException::class, $expected)
        ->and(fn () => $store->requireActive())->toThrow(EcfCertificateException::class, $expected)
        ->and($store->current()->is($certificate))->toBeTrue();
});
