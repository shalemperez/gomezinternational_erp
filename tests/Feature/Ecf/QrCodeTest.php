<?php

use App\Enums\EcfEnvironment;
use App\Services\Ecf\QrCodeGenerator;
use App\Support\Ecf\EcfQrUrl;
use Carbon\CarbonImmutable;

test('qr urls follow the dgii consulta timbre formats with encoded parameters', function () {
    $url = EcfQrUrl::forEcf(EcfEnvironment::Test, '131880738', '101010101', 'E310000000001', CarbonImmutable::parse('2026-11-01'), '1234.50', CarbonImmutable::parse('2026-11-01 09:05:00'), 'aB+/1=');
    expect($url)->toBe('https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=131880738&rnccomprador=101010101&encf=E310000000001&fechaemision=01-11-2026&montototal=1234.50&fechafirma=01-11-2026%2009%3A05%3A00&codigoseguridad=aB%2B%2F1%3D');
    expect(EcfQrUrl::forRfce(EcfEnvironment::Prod, '131880738', 'E320000000064', '6225.09', 'uabnyh'))
        ->toBe('https://fc.dgii.gov.do/ecf/consultatimbrefc?rncemisor=131880738&encf=E320000000064&montototal=6225.09&codigoseguridad=uabnyh');
    expect(EcfQrUrl::forEcf(EcfEnvironment::Prod, '131880738', null, 'E320000000001', CarbonImmutable::parse('2026-11-01'), '300000.00', CarbonImmutable::parse('2026-11-01 09:05:00'), 'abcdef'))
        ->not->toContain('rnccomprador');
});

test('the qr png encodes the url and decodes back', function () {
    $url = EcfQrUrl::forEcf(EcfEnvironment::Prod, '131880738', '101010101', 'E310000000001', CarbonImmutable::parse('2026-11-01'), '1234.50', CarbonImmutable::parse('2026-11-01 09:05:00'), 'abcdef');
    $dataUri = app(QrCodeGenerator::class)->pngDataUri($url);
    expect($dataUri)->toStartWith('data:image/png;base64,');
    $png = base64_decode(substr($dataUri, strlen('data:image/png;base64,')));
    expect(substr($png, 1, 3))->toBe('PNG')->and(app(QrCodeGenerator::class)->decode($png))->toBe($url);
});

test('the qr signing time is printed in santo domingo time whatever timezone the instant carries', function () {
    $url = EcfQrUrl::forEcf(EcfEnvironment::Test, '131880738', null, 'E310000000001', CarbonImmutable::parse('2026-11-01'), '100.00', CarbonImmutable::parse('2026-11-01 02:30:00', 'UTC'), 'abcdef');
    expect($url)->toContain('fechafirma=31-10-2026%2022%3A30%3A00');
});
