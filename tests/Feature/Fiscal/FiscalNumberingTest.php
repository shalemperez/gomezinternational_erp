<?php

use App\Enums\NcfType;
use App\Exceptions\FiscalNumberingException;
use App\Models\NcfSequence;
use App\Services\Fiscal\FiscalNumberingService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

test('assigns consecutive 11-character ncfs and tracks exhaustion', function () {
    $seq = activeSequence('B01', ['range_from' => 5, 'range_to' => 6, 'next_number' => 5]);
    $s = app(FiscalNumberingService::class);

    $a = $s->assign(NcfType::B01, Carbon::today());
    $b = $s->assign(NcfType::B01, Carbon::today());

    expect($a->ncf)->toBe('B0100000005')->and(strlen($a->ncf))->toBe(11)->and($a->expiresOn?->toDateString())->toBe($seq->expires_on->toDateString())
        ->and($b->ncf)->toBe('B0100000006')
        ->and($seq->fresh()->next_number)->toBe(7)->and($seq->fresh()->exhausted_at)->not->toBeNull()->and($seq->fresh()->remaining)->toBe(0)
        ->and(Activity::inLog('ncf_sequences')->where('event', 'exhausted')->where('subject_id', $seq->id)->exists())->toBeTrue();
});

test('issuing with an exhausted sequence is refused', function () {
    activeSequence('B01', ['range_from' => 1, 'range_to' => 1, 'next_number' => 2, 'exhausted_at' => now()]);
    expect(fn () => app(FiscalNumberingService::class)->assign(NcfType::B01, Carbon::today()))
        ->toThrow(FiscalNumberingException::class, 'La secuencia de B01 está agotada; registra una nueva secuencia autorizada por la DGII.');
});

test('issuing with an expired sequence is refused', function () {
    activeSequence('B01', ['expires_on' => '2025-12-31']);
    expect(fn () => app(FiscalNumberingService::class)->assign(NcfType::B01, Carbon::parse('2026-01-01')))
        ->toThrow(FiscalNumberingException::class, 'La secuencia de B01 venció el 31/12/2025; registra una nueva secuencia autorizada por la DGII.');
});

test('missing sequence is refused with the type label', function () {
    expect(fn () => app(FiscalNumberingService::class)->assign(NcfType::B14, Carbon::today()))
        ->toThrow(FiscalNumberingException::class, 'No hay una secuencia activa de Regímenes Especiales (B14).');
});

test('consumo and credit note sequences never expire', function () {
    activeSequence('B02', ['expires_on' => null]);
    $n = app(FiscalNumberingService::class)->assign(NcfType::B02, Carbon::parse('2030-01-01'));
    expect($n->ncf)->toBe('B0200000001')->and($n->expiresOn)->toBeNull()->and(NcfType::B02->expires())->toBeFalse()->and(NcfType::B04->expires())->toBeFalse()->and(NcfType::B01->expires())->toBeTrue();
});

test('fiscal numbers are assigned inside a locking transaction and never repeat', function () {
    activeSequence('B02', ['range_to' => 50]);
    $s = app(FiscalNumberingService::class);
    $all = [];
    foreach (range(1, 30) as $i) {
        $all[] = $s->assign(NcfType::B02, Carbon::today())->ncf;
    }
    expect(array_unique($all))->toHaveCount(30);
    expect(fn () => NcfSequence::factory()->create(['ncf_type' => 'B02', 'range_from' => 1, 'range_to' => 10]))->toThrow(QueryException::class); // unique (ncf_type, range_from)
});

test('when two active sequences exist the one with the earliest range is used first', function () {
    activeSequence('B01', ['range_from' => 1, 'range_to' => 1, 'next_number' => 2, 'exhausted_at' => now()]);
    activeSequence('B01', ['range_from' => 200, 'range_to' => 300, 'next_number' => 200]);
    expect(app(FiscalNumberingService::class)->assign(NcfType::B01, Carbon::today())->ncf)->toBe('B0100000200');
});

test('non-expiring types never report an expiry date even when the sequence has one', function () {
    activeSequence('B02', ['expires_on' => '2030-12-31']);
    $n = app(FiscalNumberingService::class)->assign(NcfType::B02, Carbon::today());
    expect($n->ncf)->toBe('B0200000001')->and($n->expiresOn)->toBeNull();
});
