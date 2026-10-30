<?php

use App\Jobs\CheckNcfSequences;
use App\Models\NcfSequence;
use App\Notifications\NcfSequenceAlert;
use App\Services\Fiscal\SequenceAlerts;
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
