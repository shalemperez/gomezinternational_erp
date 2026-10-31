<?php

use App\Models\EcfCertificate;
use App\Models\EcfDocument;
use App\Models\Setting;
use App\Services\Fiscal\FiscalNumberingService;
use App\Services\Settings;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Spatie\Activitylog\Models\Activity;

function ecfSettingsPayload(array $over = []): array
{
    return array_replace_recursive(['fiscal' => ['mode' => 'ncf', 'ecf_cutover_date' => ''], 'ecf' => ['provider' => 'fake', 'environment' => 'test', 'rfce_threshold' => '250000.00', 'company' => ['municipio' => '010100', 'provincia' => '010000'], 'pending_alert_hours' => '24', 'receiver_base_url' => 'https://erp.gomez.test', 'alanube' => ['api_key' => '']]], $over);
}

test('the e-cf settings page is owner-only and never exposes secrets', function () {
    actingAsRole('accounting');
    $this->get(route('admin.ecf-settings.index'))->assertForbidden();
    actingAsRole('owner');
    activeCertificate();
    app(Settings::class)->set('ecf.alanube.api_key', Crypt::encryptString('super-secret'));
    $response = $this->get(route('admin.ecf-settings.index'))->assertOk()->assertInertia(fn ($p) => $p->component('admin/ecf-settings/index')->where('hasAlanubeKey', true)->where('certificate.is_active', true)->where('certificate.rnc', '131880738')->missing('values.ecf.alanube.api_key')->where('receiverUrls.reception', config('app.url').'/fe/recepcion/api/ecf'));
    expect($response->getContent())->not->toContain('super-secret')->not->toContain('p12_encrypted');
});

test('uploading a p12 activates it and a wrong password is reported on the field', function () {
    actingAsRole('owner');
    $this->post(route('admin.ecf-settings.certificate.store'), ['certificate' => UploadedFile::fake()->createWithContent('cert.p12', fakeP12('clave')), 'password' => 'clave', 'name' => 'Cert 2026'])->assertRedirect(route('admin.ecf-settings.index'))->assertSessionHas('success');
    expect(EcfCertificate::query()->where('is_active', true)->value('name'))->toBe('Cert 2026');
    $this->post(route('admin.ecf-settings.certificate.store'), ['certificate' => UploadedFile::fake()->createWithContent('cert.p12', fakeP12('clave')), 'password' => 'otra'])->assertSessionHasErrors('certificate');
    $this->post(route('admin.ecf-settings.certificate.store'), ['certificate' => UploadedFile::fake()->create('cert.txt', 1), 'password' => 'x'])->assertSessionHasErrors('certificate');
});

test('switching to ecf mode requires a valid certificate for dgii and sequences for the environment', function () {
    actingAsRole('owner');
    $payload = ecfSettingsPayload(['fiscal' => ['mode' => 'ecf', 'ecf_cutover_date' => today()->toDateString()], 'ecf' => ['provider' => 'dgii']]);
    $this->put(route('admin.ecf-settings.update'), $payload)->assertSessionHasErrors(['fiscal.mode' => 'Para activar el modo e-CF con DGII directo carga un certificado digital vigente.']);
    activeCertificate();
    $this->put(route('admin.ecf-settings.update'), $payload)->assertSessionHasErrors(['fiscal.mode' => 'Registra secuencias e-NCF activas (E31 y E32) para el ambiente Pruebas (TesteCF) antes de activar el modo e-CF.']);
    activeSequence('E31');
    activeSequence('E32');
    $this->put(route('admin.ecf-settings.update'), $payload)->assertRedirect(route('admin.ecf-settings.index'))->assertSessionHas('success', 'Configuración de facturación electrónica guardada');
    expect(app(Settings::class)->get('fiscal.mode'))->toBe('ecf')->and(app(Settings::class)->get('ecf.provider'))->toBe('dgii')->and(app(FiscalNumberingService::class)->mode())->toBe('ecf')
        ->and(Activity::inLog('settings')->where('event', 'updated')->latest('id')->first()->properties['keys'])->toContain('fiscal.mode');
});

test('switching to alanube requires an api key which is stored encrypted and never echoed back', function () {
    actingAsRole('owner');
    activeSequence('E31');
    activeSequence('E32');
    $payload = ecfSettingsPayload(['fiscal' => ['mode' => 'ecf'], 'ecf' => ['provider' => 'alanube']]);
    $this->put(route('admin.ecf-settings.update'), $payload)->assertSessionHasErrors(['fiscal.mode' => 'Para activar el modo e-CF con Alanube registra la API key.']);
    $this->put(route('admin.ecf-settings.update'), ecfSettingsPayload(['fiscal' => ['mode' => 'ecf'], 'ecf' => ['provider' => 'alanube', 'alanube' => ['api_key' => 'live-key-123']]]))->assertRedirect();
    $stored = Setting::query()->where('key', 'ecf.alanube.api_key')->value('value')['v'];
    expect($stored)->not->toBe('live-key-123')->and(Crypt::decryptString($stored))->toBe('live-key-123');
    $this->put(route('admin.ecf-settings.update'), ecfSettingsPayload(['fiscal' => ['mode' => 'ecf'], 'ecf' => ['provider' => 'alanube']]))->assertRedirect();
    expect(Crypt::decryptString(Setting::query()->where('key', 'ecf.alanube.api_key')->value('value')['v']))->toBe('live-key-123');
    $logged = json_encode(Activity::inLog('settings')->get()->pluck('properties'));
    expect($logged)->not->toContain('live-key-123');
});

test('the environment cannot change while documents of another environment exist', function () {
    actingAsRole('owner');
    EcfDocument::factory()->create(['environment' => 'test']);
    $this->put(route('admin.ecf-settings.update'), ecfSettingsPayload(['ecf' => ['environment' => 'prod']]))->assertSessionHasErrors(['ecf.environment' => 'Esta base de datos ya tiene e-CF del ambiente Pruebas (TesteCF); usa una instalación separada para certificación o pruebas.']);
    $this->put(route('admin.ecf-settings.update'), ecfSettingsPayload(['ecf' => ['environment' => 'test']]))->assertRedirect();
});

test('the fake provider is refused in production and bounds are enforced', function () {
    actingAsRole('owner');
    $this->app['env'] = 'production';
    $this->withoutMiddleware(PreventRequestForgery::class);
    $this->put(route('admin.ecf-settings.update'), ecfSettingsPayload())->assertSessionHasErrors(['ecf.provider' => 'El proveedor simulado no se permite en producción.']);
    $this->app['env'] = 'testing';
    $this->put(route('admin.ecf-settings.update'), ecfSettingsPayload(['ecf' => ['pending_alert_hours' => '100', 'company' => ['municipio' => '12']]]))->assertSessionHasErrors(['ecf.pending_alert_hours', 'ecf.company.municipio']);
});

test('deactivating the certificate is audited and blocks ecf mode with dgii', function () {
    actingAsRole('owner');
    $cert = activeCertificate();
    activeSequence('E31');
    activeSequence('E32');
    $this->delete(route('admin.ecf-settings.certificate.deactivate', $cert))->assertRedirect()->assertSessionHas('success', 'Certificado desactivado');
    expect($cert->fresh()->is_active)->toBeFalse()->and(Activity::inLog('ecf_certificates')->where('event', 'deactivated')->where('subject_id', $cert->id)->exists())->toBeTrue();
    $this->put(route('admin.ecf-settings.update'), ecfSettingsPayload(['fiscal' => ['mode' => 'ecf'], 'ecf' => ['provider' => 'dgii']]))->assertSessionHasErrors('fiscal.mode');
});

test('secret settings never reach the activity log', function () {
    app(Settings::class)->set('ecf.alanube.webhook_key', 'hook-secret-123');
    app(Settings::class)->set('ecf.alanube.webhook_key', 'hook-secret-456');
    app(Settings::class)->set('ecf.alanube.api_key', Crypt::encryptString('api-secret-789'));
    app(Settings::class)->set('ecf.provider', 'alanube');
    $logged = Activity::inLog('settings')->get()->map(fn ($activity) => json_encode($activity->attribute_changes).json_encode($activity->properties))->implode(' ');
    $providerRow = Activity::inLog('settings')->where('subject_id', Setting::query()->where('key', 'ecf.provider')->value('id'))->latest('id')->firstOrFail();
    expect($logged)->not->toContain('hook-secret')->not->toContain('eyJ')
        ->and(json_encode($providerRow->attribute_changes))->toContain('alanube');
});

test('the certificate rnc warning ignores dashes in the company rnc', function () {
    actingAsRole('owner');
    activeCertificate(); // subject serialNumber 131880738
    app(Settings::class)->set('company.rnc', '1-31-88073-8');
    $this->get(route('admin.ecf-settings.index'))->assertOk()->assertInertia(fn ($p) => $p->where('warnings', []));
    app(Settings::class)->set('company.rnc', '1-01-01010-1');
    $this->get(route('admin.ecf-settings.index'))->assertInertia(fn ($p) => $p->where('warnings.0', 'El certificado pertenece al RNC 131880738, distinto al RNC de la empresa 1-01-01010-1.'));
});
