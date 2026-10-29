<?php

use App\Support\Money\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\DivisionByZeroException;

test('money rounds half up at 2 decimals without float drift', function () {
    expect(Decimal::money('1.005'))->toBe('1.01')
        ->and(Decimal::money('2.675'))->toBe('2.68')
        ->and(Decimal::money('1.004'))->toBe('1.00')
        ->and(Decimal::money(7))->toBe('7.00')
        ->and(Decimal::money('-1.005'))->toBe('-1.01');
});

test('qty and rate scales', function () {
    expect(Decimal::qty('1'))->toBe('1.0000')->and(Decimal::rate('58.1'))->toBe('58.100000');
});

test('mul div percent sum', function () {
    expect(Decimal::mul('3', '0.1', 2))->toBe('0.30')
        ->and(Decimal::mul('12.3456', '2', 4))->toBe('24.6912')
        ->and(Decimal::div('10', '3', 4))->toBe('3.3333')
        ->and(Decimal::div('2', '3', 2))->toBe('0.67')
        ->and(Decimal::percent('1000', '18'))->toBe('180.00')
        ->and(Decimal::percent('123.45', '18'))->toBe('22.22')
        ->and(Decimal::percent('100', '16', 4))->toBe('16.0000')
        ->and(Decimal::sum(['0.1', '0.2', '0.3']))->toBe('0.60')
        ->and(Decimal::sum([]))->toBe('0.00');
});

test('comparisons', function () {
    expect(Decimal::isZero('0.000'))->toBeTrue()->and(Decimal::isZero('0.01'))->toBeFalse()
        ->and(Decimal::cmp('1.10', '1.1'))->toBe(0)->and(Decimal::cmp('1', '2'))->toBe(-1)->and(Decimal::cmp('3', '2'))->toBe(1);
});

test('accepts BigDecimal and rejects floats even in weak typing mode', function () {
    expect(Decimal::money(BigDecimal::of('5.555')))->toBe('5.56');
    expect(fn () => Decimal::of(1.5))->toThrow(TypeError::class, 'Decimal::of no acepta float');
});

test('floats are rejected by every public entry point', function (callable $call) {
    expect($call)->toThrow(TypeError::class);
})->with([
    'money' => [fn () => Decimal::money(1.5)],
    'qty' => [fn () => Decimal::qty(1.5)],
    'rate' => [fn () => Decimal::rate(1.5)],
    'scale' => [fn () => Decimal::scale(1.5, 2)],
    'mul' => [fn () => Decimal::mul('1', 1.5, 2)],
    'div' => [fn () => Decimal::div(1.5, '3', 2)],
    'percent' => [fn () => Decimal::percent('100', 1.5, 2)],
    'sum' => [fn () => Decimal::sum(['1', 1.5])],
    'isZero' => [fn () => Decimal::isZero(0.0)],
    'cmp' => [fn () => Decimal::cmp(1.5, '1')],
]);

test('half up rounding at boundaries and for negatives', function () {
    expect(Decimal::money('0.005'))->toBe('0.01')
        ->and(Decimal::money('-0.005'))->toBe('-0.01')
        ->and(Decimal::money('0.0049'))->toBe('0.00')
        ->and(Decimal::div('-2', '3', 2))->toBe('-0.67')
        ->and(Decimal::div('2', '3', 2))->toBe('0.67')
        ->and(Decimal::percent('-10', '18', 2))->toBe('-1.80')
        ->and(Decimal::mul('1.005', '1', 2))->toBe('1.01')
        ->and(Decimal::mul('0.335', '3', 2))->toBe('1.01');
});

test('division by zero throws', function () {
    expect(fn () => Decimal::div('1', '0', 2))->toThrow(DivisionByZeroException::class);
});
