<?php

use App\Enums\NcfType;
use App\Exceptions\FiscalNumberingException;
use App\Models\NcfSequence;
use App\Services\Fiscal\FiscalNumberingService;
use App\Services\Fiscal\SequenceAlerts;
use App\Services\Settings;

test('in ecf mode traditional types resolve to their electronic counterpart and get 13-character e-ncfs from the environment sequences', function () {
    ecfMode(environment: 'test');
    activeSequence('E31', ['environment' => 'prod', 'range_from' => 5000, 'range_to' => 6000, 'next_number' => 5000]);
    $s = app(FiscalNumberingService::class);
    $a = $s->assign(NcfType::B01, today());
    $b = $s->assign(NcfType::B02, today());
    $c = $s->assign(NcfType::E34, today());
    expect($a->ncf)->toBe('E310000000001')->and(strlen($a->ncf))->toBe(13)->and($a->type)->toBe(NcfType::E31)->and($a->expiresOn)->not->toBeNull()
        ->and($b->ncf)->toBe('E320000000001')->and($b->expiresOn)->toBeNull()
        ->and($c->ncf)->toBe('E340000000001')
        ->and(NcfSequence::query()->where('ncf_type', 'E31')->where('environment', 'prod')->value('next_number'))->toBe(5000);
});

test('electronic types are refused in ncf mode and the mode honours the cutover date', function () {
    activeSequence('E31');
    expect(fn () => app(FiscalNumberingService::class)->assign(NcfType::E31, today()))->toThrow(FiscalNumberingException::class, 'El modo fiscal actual (NCF tradicional) no permite emitir e-CF.');
    $settings = app(Settings::class);
    $settings->set('fiscal.mode', 'ecf');
    $settings->set('fiscal.ecf_cutover_date', today()->addDay()->toDateString());
    expect(app(FiscalNumberingService::class)->mode())->toBe('ncf');
    $settings->set('fiscal.ecf_cutover_date', today()->toDateString());
    expect(app(FiscalNumberingService::class)->mode())->toBe('ecf');
});

test('sequence alerts cover electronic types in ecf mode', function () {
    ecfMode();
    NcfSequence::query()->where('ncf_type', 'E33')->delete();
    $alerts = collect(app(SequenceAlerts::class)->current());
    expect($alerts->firstWhere('ncf_type', 'E33')['reason'])->toBe('missing')->and($alerts->pluck('ncf_type')->contains('B01'))->toBeFalse();
});

test('sequence forms accept ten digit ranges for electronic types scoped to the current environment and reject oversized limits', function () {
    actingAsRole('owner');
    ecfMode(environment: 'cert');
    NcfSequence::query()->delete();
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['ncf_type' => 'E31', 'range_from' => 1, 'range_to' => 9999999999]))->assertRedirect(route('admin.ncf-sequences.index'));
    expect(NcfSequence::query()->where('ncf_type', 'E31')->first()->only(['environment', 'range_to']))->toBe(['environment' => 'cert', 'range_to' => 9999999999]);
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['ncf_type' => 'E33', 'range_from' => 1, 'range_to' => 10000000000]))->assertSessionHasErrors(['range_to' => 'El rango de E33 admite hasta 10 dígitos.']);
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['ncf_type' => 'B01', 'range_from' => 1, 'range_to' => 100000000]))->assertSessionHasErrors(['range_to' => 'El rango de B01 admite hasta 8 dígitos.']);
    $this->get(route('admin.ncf-sequences.index'))->assertInertia(fn ($p) => $p->component('admin/ncf-sequences/index')->where('environment', 'cert')->where('rows.data.0.sequence_digits', 10)->where('rows.data.0.environment_label', 'Certificación (CerteCF)'));
});
