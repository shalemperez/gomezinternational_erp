<?php

use App\Models\AccountingEvent;
use App\Models\Currency;
use App\Models\User;
use App\Services\Accounting\AccountingEventRecorder;
use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Carbon;

beforeEach(fn () => (new CurrencySeeder)->run());

test('records an event with document reference, currency, rate and payload', function () {
    $user = User::factory()->create();
    $event = app(AccountingEventRecorder::class)->record('probe_event', $user, ['amount' => '100.00', 'currency' => 'DOP', 'exchange_rate' => '1.000000'], Carbon::parse('2026-11-02'));

    expect($event->type)->toBe('probe_event')
        ->and($event->document_type)->toBe(User::class)
        ->and($event->document_id)->toBe($user->id)
        ->and($event->occurred_on->format('Y-m-d'))->toBe('2026-11-02')
        ->and($event->currency_id)->toBe(Currency::base()->id)
        ->and($event->exchange_rate)->toBe('1.000000')
        ->and($event->payload['amount'])->toBe('100.00')
        ->and($event->processed_at)->toBeNull()
        ->and(AccountingEvent::count())->toBe(1);
});

test('payload must carry currency and exchange_rate', function () {
    expect(fn () => app(AccountingEventRecorder::class)->record('probe_event', User::factory()->create(), ['amount' => '1']))
        ->toThrow(InvalidArgumentException::class, 'El evento contable requiere currency y exchange_rate');
});
