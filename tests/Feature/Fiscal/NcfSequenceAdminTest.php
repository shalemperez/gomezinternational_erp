<?php

use App\Jobs\CheckNcfSequences;
use App\Models\Invoice;
use App\Models\NcfSequence;
use App\Notifications\NcfSequenceAlert;
use App\Services\Fiscal\SequenceAlerts;
use App\Services\Settings;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

test('index requires view permission and accounting can manage', function () {
    actingAsRole('sales');
    $this->get(route('admin.ncf-sequences.index'))->assertForbidden();
    actingAsRole('management');
    $this->get(route('admin.ncf-sequences.index'))->assertOk()->assertInertia(fn ($p) => $p->component('admin/ncf-sequences/index')->where('canManage', false));
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload())->assertForbidden();
    actingAsRole('accounting');
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload())->assertRedirect(route('admin.ncf-sequences.index'))->assertSessionHas('success', 'Secuencia registrada');
    expect(NcfSequence::count())->toBe(1)->and(NcfSequence::first()->next_number)->toBe(1)
        ->and(Activity::inLog('ncf_sequences')->where('event', 'created')->exists())->toBeTrue();
});

test('validation: range, expiry required for expiring types, overlap and consumo without expiry', function () {
    actingAsRole('owner');
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['range_from' => 10, 'range_to' => 5]))->assertSessionHasErrors(['range_to']);
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['expires_on' => '']))->assertSessionHasErrors(['expires_on']);
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['ncf_type' => 'B02', 'expires_on' => '']))->assertRedirect(route('admin.ncf-sequences.index'));
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['range_from' => 50, 'range_to' => 150]))->assertRedirect();
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['range_from' => 100, 'range_to' => 120]))->assertSessionHasErrors(['range_from' => 'El rango se solapa con otra secuencia del mismo tipo.']);
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['range_from' => 99999999, 'range_to' => 100000000]))->assertSessionHasErrors(['range_to']);
});

test('a used sequence cannot shrink below next_number nor change type', function () {
    actingAsRole('owner');
    $seq = activeSequence('B01', ['range_from' => 1, 'range_to' => 100, 'next_number' => 40]);
    $this->put(route('admin.ncf-sequences.update', $seq), validNcfSequencePayload(['range_to' => 30]))->assertSessionHasErrors(['range_to' => 'La secuencia ya emitió hasta el número 39.']);
    $this->put(route('admin.ncf-sequences.update', $seq), validNcfSequencePayload(['ncf_type' => 'B02', 'range_to' => 100]))->assertSessionHasErrors(['ncf_type']);
    $this->put(route('admin.ncf-sequences.update', $seq), validNcfSequencePayload(['range_to' => 200]))->assertRedirect(route('admin.ncf-sequences.index'));
    expect($seq->fresh()->range_to)->toBe(200);
});

test('alerts report low remaining, expiring, expired and missing sales sequences and the job notifies', function () {
    Notification::fake();
    $owner = actingAsRole('owner');
    activeSequence('B01', ['range_from' => 1, 'range_to' => 100, 'next_number' => 95]);                 // 6 left = 6 % → low
    activeSequence('B02', ['range_from' => 1, 'range_to' => 1000]);                                        // fine
    activeSequence('B04', ['range_from' => 1, 'range_to' => 10, 'next_number' => 11, 'exhausted_at' => now()]); // exhausted → low (0)
    activeSequence('B14', ['expires_on' => now()->addDays(10)->toDateString()]);                            // expiring
    activeSequence('B15', ['expires_on' => now()->subDay()->toDateString()]);                               // expired

    $alerts = collect(app(SequenceAlerts::class)->current());
    expect($alerts->firstWhere('ncf_type', 'B01')['reason'])->toBe('low')
        ->and($alerts->firstWhere('ncf_type', 'B04')['reason'])->toBe('low')
        ->and($alerts->firstWhere('ncf_type', 'B14')['reason'])->toBe('expiring')
        ->and($alerts->firstWhere('ncf_type', 'B15')['reason'])->toBe('expired')
        ->and($alerts->firstWhere('ncf_type', 'B03')['reason'])->toBe('missing')
        ->and($alerts->firstWhere('ncf_type', 'B02'))->toBeNull();

    (new CheckNcfSequences)->handle(app(SequenceAlerts::class));
    Notification::assertSentTo($owner, NcfSequenceAlert::class);
    expect(Activity::inLog('ncf_sequences')->where('event', 'alerts_checked')->exists())->toBeTrue();
});

test('editing an unused sequence moves next_number to the new range start', function () {
    actingAsRole('owner');
    $seq = activeSequence('B01', ['range_from' => 1, 'range_to' => 100, 'next_number' => 1]);
    $this->put(route('admin.ncf-sequences.update', $seq), validNcfSequencePayload(['range_from' => 500, 'range_to' => 600]))->assertRedirect(route('admin.ncf-sequences.index'));
    expect($seq->fresh()->next_number)->toBe(500)->and($seq->fresh()->range_from)->toBe(500);
});

test('a used sequence cannot change its range start', function () {
    actingAsRole('owner');
    $seq = activeSequence('B01', ['range_from' => 1, 'range_to' => 100, 'next_number' => 40]);
    $this->put(route('admin.ncf-sequences.update', $seq), validNcfSequencePayload(['range_from' => 10]))->assertSessionHasErrors(['range_from' => 'No se puede cambiar el inicio de una secuencia usada.']);
    expect($seq->fresh()->range_from)->toBe(1);
});

test('extending an exhausted sequence clears exhausted_at', function () {
    actingAsRole('owner');
    $seq = activeSequence('B01', ['range_from' => 1, 'range_to' => 100, 'next_number' => 101, 'exhausted_at' => now()]);
    $this->put(route('admin.ncf-sequences.update', $seq), validNcfSequencePayload(['range_to' => 200]))->assertRedirect(route('admin.ncf-sequences.index'));
    expect($seq->fresh()->exhausted_at)->toBeNull();
});

test('a sequence expiring today is still usable and not reported as expired', function () {
    activeSequence('B01', ['range_from' => 1, 'range_to' => 1000, 'next_number' => 1, 'expires_on' => today()->toDateString()]);
    $alerts = collect(app(SequenceAlerts::class)->current())->where('ncf_type', 'B01');
    expect($alerts->where('reason', 'expired'))->toHaveCount(0);
});

test('a sequence range cannot include ncfs already used by invoices outside it', function () {
    actingAsRole('owner');
    Invoice::factory()->create(['kind' => 'historical', 'status' => 'issued', 'fiscal_mode' => 'external', 'ncf' => 'B0100000123', 'ncf_type' => 'B01', 'issued_at' => now()]);
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['range_from' => 1, 'range_to' => 200]))
        ->assertSessionHasErrors(['range_from' => 'El rango incluye el NCF B0100000123, ya emitido; inicia la secuencia después del último NCF usado.']);
    $this->post(route('admin.ncf-sequences.store'), validNcfSequencePayload(['range_from' => 124, 'range_to' => 200]))->assertRedirect(route('admin.ncf-sequences.index'));
    $seq = NcfSequence::firstOrFail();
    Invoice::factory()->create(['status' => 'issued', 'fiscal_mode' => 'ncf', 'ncf' => 'B0100000124', 'ncf_type' => 'B01', 'ncf_sequence_id' => $seq->id, 'issued_at' => now()]);
    $this->put(route('admin.ncf-sequences.update', $seq), validNcfSequencePayload(['range_from' => 124, 'range_to' => 300]))->assertRedirect(route('admin.ncf-sequences.index')); // its own NCFs do not collide
});

test('editing an electronic sequence keeps its environment', function () {
    actingAsRole('owner');
    app(Settings::class)->set('ecf.environment', 'prod');
    $seq = activeSequence('E31', ['environment' => 'cert', 'range_from' => 1, 'range_to' => 100, 'next_number' => 1]);
    $this->put(route('admin.ncf-sequences.update', $seq), validNcfSequencePayload(['ncf_type' => 'E31', 'range_from' => 1, 'range_to' => 200, 'is_active' => '0']))->assertRedirect(route('admin.ncf-sequences.index'));
    expect($seq->fresh()->environment)->toBe('cert')->and($seq->fresh()->range_to)->toBe(200);
});
