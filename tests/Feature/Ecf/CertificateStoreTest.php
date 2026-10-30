<?php

use App\Exceptions\EcfCertificateException;
use App\Models\EcfCertificate;
use App\Models\User;
use App\Services\Ecf\CertificateStore;
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
