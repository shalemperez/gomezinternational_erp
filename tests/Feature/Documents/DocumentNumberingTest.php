<?php

use App\Enums\DocumentKind;
use App\Models\DocumentSequence;
use App\Services\Documents\DocumentNumbering;
use Database\Seeders\DocumentSequenceSeeder;

beforeEach(fn () => (new DocumentSequenceSeeder)->run());

test('seeder creates one sequence per kind idempotently', function () {
    (new DocumentSequenceSeeder)->run();
    expect(DocumentSequence::count())->toBe(6)->and(DocumentSequence::where('kind', 'invoice')->first()->prefix)->toBe('FAC');
});

test('next returns zero padded consecutive numbers per kind', function () {
    $n = app(DocumentNumbering::class);
    expect($n->next(DocumentKind::Proforma))->toBe('COT-000001')
        ->and($n->next(DocumentKind::Proforma))->toBe('COT-000002')
        ->and($n->next(DocumentKind::Invoice))->toBe('FAC-000001')
        ->and($n->next(DocumentKind::Dispatch))->toBe('CND-000001');
});

test('concurrent next numbers are unique', function () {
    $n = app(DocumentNumbering::class);
    $numbers = [];
    foreach (range(1, 25) as $i) {
        $numbers[] = $n->next(DocumentKind::Receipt);
    }
    expect(array_unique($numbers))->toHaveCount(25)->and(end($numbers))->toBe('REC-000025');
});

test('kind labels and prefixes', function () {
    expect(DocumentKind::CreditNote->prefix())->toBe('NC')->and(DocumentKind::CreditNote->label())->toBe('Nota de crédito');
});
