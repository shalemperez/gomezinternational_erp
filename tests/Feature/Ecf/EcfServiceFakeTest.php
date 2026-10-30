<?php

use App\Enums\EcfEnvironment;
use App\Enums\EcfStatus;
use App\Enums\NcfType;
use App\Exceptions\EcfException;
use App\Models\Client;
use App\Models\NcfSequence;
use App\Models\User;
use App\Services\Ecf\EcfService;
use App\Services\Ecf\EcfStorage;
use App\Services\Ecf\Providers\FakeEcfProvider;
use App\Services\Settings;
use App\Support\Fiscal\FiscalNumber;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    (new DocumentSequenceSeeder)->run();
});

test('create builds, signs and stores an immutable xml for an invoice and records the audit rows', function () {
    ecfSettings();
    $cert = activeCertificate();
    $invoice = issuedInvoice();
    $doc = app(EcfService::class)->create($invoice, new FiscalNumber('E310000000001', NcfType::E31, CarbonImmutable::parse('2027-12-31'), NcfSequence::query()->where('ncf_type', 'E31')->value('id')), User::factory()->create());

    expect($doc->status)->toBe(EcfStatus::Signed)->and($doc->flow)->toBe('ecf')->and($doc->provider)->toBe('fake')->and($doc->environment)->toBe(EcfEnvironment::Test)
        ->and(strlen($doc->security_code))->toBe(6)->and($doc->signed_at)->not->toBeNull()->and($doc->xml_path)->toBe('ecf/test/'.now()->year.'/E310000000001/131880738E310000000001.xml')
        ->and($doc->rfce_xml_path)->toBeNull()->and($doc->qr_url)->toContain('https://ecf.dgii.gov.do/testecf/consultatimbre?rncemisor=131880738')
        ->and(Storage::disk('local')->get($doc->xml_path))->toContain('<eNCF>E310000000001</eNCF>')->and(Storage::disk('local')->get($doc->xml_path))->toContain('<SignatureValue>')
        ->and($doc->document->is($invoice))->toBeTrue()
        ->and(Activity::inLog('ecf_documents')->where('subject_id', $doc->id)->whereIn('event', ['created', 'signed'])->count())->toBe(2);
});

test('the fake provider routes consumo invoices under the rfce threshold through the summary flow', function () {
    ecfSettings();
    activeCertificate();
    $client = Client::factory()->create(['tax_id' => null, 'tax_id_type' => 'none', 'taxpayer_type' => 'consumidor_final']);
    $invoice = issuedInvoice(['client_id' => $client->id]);
    $service = app(EcfService::class);
    $doc = $service->create($invoice, new FiscalNumber('E320000000001', NcfType::E32, null, NcfSequence::query()->where('ncf_type', 'E32')->value('id')), null);
    expect($doc->flow)->toBe('rfce')->and($doc->rfce_xml_path)->toBe('ecf/test/'.now()->year.'/E320000000001/RFCE-131880738E320000000001.xml')
        ->and(Storage::disk('local')->get($doc->rfce_xml_path))->toContain('<CodigoSeguridadeCF>'.$doc->security_code.'</CodigoSeguridadeCF>')
        ->and($doc->qr_url)->toStartWith('https://fc.dgii.gov.do/testecf/consultatimbrefc?rncemisor=131880738&encf=E320000000001&montototal=236.00&codigoseguridad=');

    $doc = $service->submit($doc);
    expect($doc->status)->toBe(EcfStatus::Accepted)->and($doc->track_id)->toBeNull()->and($doc->accepted_at)->not->toBeNull()
        ->and($doc->transmissions()->where('kind', 'rfce')->where('succeeded', true)->count())->toBe(1);
});

test('submit records a transmission and moves the document to sent then poll accepts it', function () {
    ecfSettings(outcome: 'in_process');
    activeCertificate();
    $service = app(EcfService::class);
    $doc = $service->create(issuedInvoice(), new FiscalNumber('E310000000002', NcfType::E31, CarbonImmutable::parse('2027-12-31'), NcfSequence::query()->where('ncf_type', 'E31')->value('id')), null);
    $doc = $service->submit($doc);
    expect($doc->status)->toBe(EcfStatus::Sent)->and($doc->track_id)->toStartWith('FAKE-')->and($doc->attempts)->toBe(1)->and($doc->next_attempt_at)->not->toBeNull()
        ->and($doc->transmissions()->where('kind', 'send')->where('succeeded', true)->count())->toBe(1);
    $doc = $service->poll($doc);
    expect($doc->status)->toBe(EcfStatus::Accepted)->and($doc->accepted_at)->not->toBeNull()->and($doc->next_attempt_at)->toBeNull()
        ->and(Activity::inLog('ecf_documents')->where('subject_id', $doc->id)->where('event', 'accepted')->exists())->toBeTrue();
    expect(fn () => $service->submit($doc))->toThrow(EcfException::class, 'El e-CF E310000000002 ya fue enviado');
});

test('a rejected result stores the dgii messages and never reuses the sequence', function () {
    ecfSettings(outcome: 'rejected');
    activeCertificate();
    $sequence = NcfSequence::query()->where('ncf_type', 'E31')->firstOrFail();
    $doc = app(EcfService::class)->create(issuedInvoice(), new FiscalNumber('E310000000001', NcfType::E31, CarbonImmutable::parse('2027-12-31'), $sequence->id), null);
    $before = $sequence->fresh()->next_number;
    $doc = app(EcfService::class)->submit($doc);
    expect($doc->status)->toBe(EcfStatus::Rejected)->and($doc->dgii_code)->toBe(2)->and($doc->dgii_messages[0]['valor'])->toBe('Rechazo simulado')->and($doc->rejected_at)->not->toBeNull()
        ->and($sequence->fresh()->next_number)->toBe($before)
        ->and(Activity::inLog('ecf_documents')->where('subject_id', $doc->id)->where('event', 'rejected')->exists())->toBeTrue();
});

test('an unavailable provider records the failure and schedules a retry with backoff', function () {
    ecfSettings(outcome: 'unavailable');
    activeCertificate();
    $service = app(EcfService::class);
    $doc = $service->create(issuedInvoice(), new FiscalNumber('E310000000003', NcfType::E31, CarbonImmutable::parse('2027-12-31'), NcfSequence::query()->where('ncf_type', 'E31')->value('id')), null);
    try {
        $service->submit($doc);
    } catch (EcfException $e) {
        $service->recordFailure($doc, $e, 'send');
    }
    $doc->refresh();
    expect($doc->status)->toBe(EcfStatus::Signed)->and($doc->attempts)->toBe(1)->and($doc->last_error)->toBe('La DGII no está disponible (simulado).')
        ->and($doc->next_attempt_at->diffInMinutes(now()->addMinute(), true))->toBeLessThan(1)
        ->and($doc->transmissions()->where('succeeded', false)->where('error', 'La DGII no está disponible (simulado).')->count())->toBe(1);
});

test('immutable storage refuses to overwrite an existing file', function () {
    $storage = app(EcfStorage::class);
    $storage->putNew('ecf/test/2026/E310000000001/a.xml', '<a/>');
    expect(fn () => $storage->putNew('ecf/test/2026/E310000000001/a.xml', '<b/>'))->toThrow(EcfException::class, 'ya existe y es inmutable');
    expect($storage->get('ecf/test/2026/E310000000001/a.xml'))->toBe('<a/>');
});

test('without a certificate the fake provider signs with a pseudo signature and the service resolves settings', function () {
    ecfSettings(environment: 'cert');
    $service = app(EcfService::class);
    expect($service->provider())->toBeInstanceOf(FakeEcfProvider::class)->and($service->environment())->toBe(EcfEnvironment::Cert)->and($service->rfceThreshold())->toBe('250000.00');
    $doc = $service->create(issuedInvoice(), new FiscalNumber('E310000000004', NcfType::E31, CarbonImmutable::parse('2027-12-31'), NcfSequence::query()->where('ncf_type', 'E31')->value('id')), null);
    expect($doc->status)->toBe(EcfStatus::Signed)->and(strlen($doc->security_code))->toBe(6)->and($doc->environment)->toBe(EcfEnvironment::Cert);
    app(Settings::class)->set('ecf.provider', 'nope');
    expect(fn () => app(EcfService::class)->provider())->toThrow(EcfException::class, 'Proveedor e-CF no soportado: nope');
});
