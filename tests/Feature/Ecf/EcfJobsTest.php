<?php

use App\Enums\EcfStatus;
use App\Enums\NcfType;
use App\Jobs\CheckEcfAlerts;
use App\Jobs\PollEcfStatus;
use App\Jobs\RetryPendingEcfDocuments;
use App\Jobs\SubmitEcfDocument;
use App\Models\EcfDocument;
use App\Models\NcfSequence;
use App\Notifications\EcfAlert;
use App\Notifications\EcfRejected;
use App\Services\Ecf\EcfService;
use App\Services\Fiscal\FiscalNumberingService;
use App\Services\Settings;
use App\Support\Fiscal\FiscalNumber;
use Carbon\CarbonImmutable;
use Database\Seeders\DocumentSequenceSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    Storage::fake('local');
    (new DocumentSequenceSeeder)->run();
    activeCertificate();
});

/** Creates a signed e-CF 31 for a fresh invoice with the fake provider configured to the given outcome. */
function ecfDocFor(string $outcome): EcfDocument
{
    ecfSettings(outcome: $outcome);
    $number = 'E31'.str_pad((string) (EcfDocument::query()->count() + 1), 10, '0', STR_PAD_LEFT);
    $sequenceId = NcfSequence::query()->where('ncf_type', 'E31')->where('environment', 'test')->value('id');

    return app(EcfService::class)->create(issuedInvoice(), new FiscalNumber($number, NcfType::E31, CarbonImmutable::parse('2027-12-31'), $sequenceId), null);
}

test('the submit job sends a signed document and chains a status poll', function () {
    $doc = ecfDocFor('in_process');
    Bus::fake([PollEcfStatus::class]);
    (new SubmitEcfDocument($doc))->handle(app(EcfService::class));
    expect($doc->fresh()->status)->toBe(EcfStatus::Sent)->and($doc->fresh()->attempts)->toBe(1);
    Bus::assertDispatched(PollEcfStatus::class, fn (PollEcfStatus $job) => $job->document->is($doc) && $job->delay !== null);
});

test('the poll job applies the final status and stops polling', function () {
    $doc = ecfDocFor('in_process');
    (new SubmitEcfDocument($doc))->handle(app(EcfService::class));
    (new PollEcfStatus($doc->fresh()))->handle(app(EcfService::class));
    expect($doc->fresh()->status)->toBe(EcfStatus::Accepted)->and($doc->fresh()->next_attempt_at)->toBeNull()->and($doc->fresh()->accepted_at)->not->toBeNull();
});

test('a dgii outage keeps the document signed, schedules a retry and alerts after the pending threshold', function () {
    Notification::fake();
    $owner = actingAsRole('owner');
    $doc = ecfDocFor('unavailable');
    (new SubmitEcfDocument($doc))->handle(app(EcfService::class));
    $doc->refresh();
    expect($doc->status)->toBe(EcfStatus::Signed)->and($doc->attempts)->toBe(1)->and($doc->next_attempt_at->isFuture())->toBeTrue()->and($doc->last_error)->toContain('no está disponible')
        ->and(Activity::inLog('ecf_documents')->where('subject_id', $doc->id)->where('event', 'retried')->exists())->toBeTrue();
    (new CheckEcfAlerts)->handle(app(EcfService::class));
    Notification::assertNothingSent();
    $this->travel(25)->hours();
    (new CheckEcfAlerts)->handle(app(EcfService::class));
    Notification::assertSentTo($owner, EcfAlert::class, fn (EcfAlert $n) => count($n->pending) === 1 && $n->pending[0]['encf'] === $doc->encf && $n->pending[0]['urgent'] === false);
    $this->travel(24)->hours();
    (new CheckEcfAlerts)->handle(app(EcfService::class));
    Notification::assertSentTo($owner, EcfAlert::class, fn (EcfAlert $n) => $n->pending[0]['urgent'] === true);
    expect(Activity::inLog('ecf_documents')->where('event', 'alerts_checked')->count())->toBe(3);
});

test('the retry sweep dispatches submits for due documents and polls for sent ones', function () {
    ecfSettings();
    $due = EcfDocument::factory()->create(['status' => 'signed', 'attempts' => 1, 'next_attempt_at' => now()->subMinute()]);
    $future = EcfDocument::factory()->create(['status' => 'signed', 'attempts' => 1, 'next_attempt_at' => now()->addHour()]);
    $fresh = EcfDocument::factory()->create(['status' => 'signed', 'attempts' => 0, 'created_at' => now()->subMinutes(5)]);
    $sent = EcfDocument::factory()->create(['status' => 'sent', 'track_id' => 'FAKE-1', 'next_attempt_at' => now()->subMinute()]);
    $accepted = EcfDocument::factory()->create(['status' => 'accepted']);
    Bus::fake([SubmitEcfDocument::class, PollEcfStatus::class]);
    (new RetryPendingEcfDocuments)->handle();
    Bus::assertDispatched(SubmitEcfDocument::class, fn ($j) => $j->document->is($due));
    Bus::assertDispatched(SubmitEcfDocument::class, fn ($j) => $j->document->is($fresh));
    Bus::assertNotDispatched(SubmitEcfDocument::class, fn ($j) => $j->document->is($future));
    Bus::assertDispatched(PollEcfStatus::class, fn ($j) => $j->document->is($sent));
    Bus::assertDispatchedTimes(SubmitEcfDocument::class, 2);
    Bus::assertDispatchedTimes(PollEcfStatus::class, 1);
    expect(Activity::inLog('ecf_documents')->where('event', 'retry_sweep')->first()->properties['submitted'])->toBe(2);
});

test('a rejection stores the dgii messages, marks the document rejected, never reuses the sequence and notifies alert recipients', function () {
    Notification::fake();
    $accounting = actingAsRole('accounting');
    $sales = actingAsRole('sales');
    $doc = ecfDocFor('rejected');
    $sequence = NcfSequence::query()->where('ncf_type', 'E31')->where('environment', 'test')->firstOrFail();
    $next = $sequence->next_number;
    (new SubmitEcfDocument($doc))->handle(app(EcfService::class));
    expect($doc->fresh()->status)->toBe(EcfStatus::Rejected)->and($doc->fresh()->dgii_messages[0]['valor'])->toBe('Rechazo simulado');
    Notification::assertSentTo($accounting, EcfRejected::class);
    Notification::assertNotSentTo($sales, EcfRejected::class);
    app(Settings::class)->set('fiscal.mode', 'ecf');
    expect(app(FiscalNumberingService::class)->assign(NcfType::B01, today())->ncf)->toBe('E31'.str_pad((string) $next, 10, '0', STR_PAD_LEFT));
});

test('the schedule registers the e-cf sweep every five minutes and the alerts daily', function () {
    $events = collect(app(Schedule::class)->events());
    expect($events->contains(fn ($e) => str_contains((string) $e->description, 'RetryPendingEcfDocuments') && $e->expression === '*/5 * * * *'))->toBeTrue()
        ->and($events->contains(fn ($e) => str_contains((string) $e->description, 'CheckEcfAlerts') && $e->expression === '40 7 * * *'))->toBeTrue();
});

test('a failing mail transport does not mark a rejected e-cf as failed', function () {
    $doc = ecfDocFor('rejected');
    Notification::shouldReceive('send')->andThrow(new RuntimeException('smtp down'));
    (new SubmitEcfDocument($doc))->handle(app(EcfService::class));
    expect($doc->fresh()->status)->toBe(EcfStatus::Rejected)->and($doc->fresh()->last_error)->toBeNull()->and($doc->fresh()->attempts)->toBe(1);
});

test('submit and poll jobs are unique per document', function () {
    $doc = ecfDocFor('in_process');
    expect(new SubmitEcfDocument($doc))->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new SubmitEcfDocument($doc))->uniqueId())->toBe((string) $doc->id)
        ->and(new PollEcfStatus($doc))->toBeInstanceOf(ShouldBeUnique::class)
        ->and((new PollEcfStatus($doc))->uniqueId())->toBe((string) $doc->id);
});

test('retries are exhausted after the maximum attempts', function () {
    config(['ecf.max_attempts' => 1]);
    $doc = ecfDocFor('unavailable');
    (new SubmitEcfDocument($doc))->handle(app(EcfService::class));
    expect($doc->fresh()->next_attempt_at)->toBeNull()
        ->and(Activity::inLog('ecf_documents')->where('subject_id', $doc->id)->where('event', 'retries_exhausted')->exists())->toBeTrue();
});
