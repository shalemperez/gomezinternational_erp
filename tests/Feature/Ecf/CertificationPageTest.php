<?php

use App\Jobs\SubmitEcfDocument;
use App\Models\EcfDocument;
use App\Services\Settings;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    (new DocumentSequenceSeeder)->run();
});

test('the certification page lists readiness checks, dgii steps and environment documents', function () {
    Bus::fake([SubmitEcfDocument::class]);
    ecfMode(environment: 'cert');
    activeCertificate();
    actingAsRole('accounting');
    $invoice = issuedInvoice();
    $this->get(route('fiscal.certification.index'))->assertOk()->assertInertia(fn ($p) => $p->component('fiscal/certification/index')
        ->where('environment', 'cert')->where('environment_label', 'Certificación (CerteCF)')->where('provider', 'fake')
        ->has('readiness', 6)->where('readiness.0.key', 'certificate')->where('readiness.0.ok', true)->where('readiness.3.key', 'provider')->where('readiness.3.ok', false)
        ->has('steps', 9)->where('steps.0.key', 'solicitud')->where('steps.0.done', false)
        ->has('documents', 1)->where('documents.0.encf', $invoice->ncf)->where('stats.pending', 1));
});

test('steps can be marked done and are audited', function () {
    actingAsRole('owner');
    $this->post(route('fiscal.certification.steps'), ['key' => 'solicitud', 'done' => true])->assertRedirect()->assertSessionHas('success', 'Paso actualizado');
    $this->post(route('fiscal.certification.steps'), ['key' => 'nope', 'done' => true])->assertSessionHasErrors('key');
    expect(app(Settings::class)->get('ecf.certification.steps'))->toBe(['solicitud'])
        ->and(Activity::inLog('settings')->where('event', 'updated')->latest('id')->first()->properties['keys'])->toBe(['ecf.certification.steps']);
    $this->post(route('fiscal.certification.steps'), ['key' => 'solicitud', 'done' => false])->assertRedirect();
    expect(app(Settings::class)->get('ecf.certification.steps'))->toBe([]);
});

test('resubmit sends the pending documents of the environment', function () {
    ecfMode(outcome: 'unavailable');
    activeCertificate();
    actingAsRole('accounting'); // automatic submissions fail → documents stay signed
    issuedInvoice();
    issuedInvoice();
    expect(EcfDocument::query()->where('status', 'signed')->count())->toBe(2);
    app(Settings::class)->set('ecf.fake.outcome', 'accepted');
    $this->post(route('fiscal.certification.resubmit'))->assertRedirect()->assertSessionHas('success', '2 e-CF en cola para reenvío');
    expect(EcfDocument::query()->where('status', 'accepted')->count())->toBe(2);
});

test('the page requires the certification permission and shows the psfe checklist for alanube', function () {
    actingAsRole('sales');
    $this->get(route('fiscal.certification.index'))->assertForbidden();
    actingAsRole('owner');
    app(Settings::class)->set('ecf.provider', 'alanube');
    $this->get(route('fiscal.certification.index'))->assertOk()->assertInertia(fn ($p) => $p->has('steps', 6)->where('steps.1.key', 'postulacion_psfe'));
});
