<?php

use App\Support\FiscalId;

test('rnc check digit and validation', function () {
    expect(FiscalId::rncCheckDigit('10185004'))->toBe(3)
        ->and(FiscalId::isValidRnc('101850043'))->toBeTrue()
        ->and(FiscalId::isValidRnc('101850042'))->toBeFalse()
        ->and(FiscalId::isValidRnc('131246796'))->toBeTrue()
        ->and(FiscalId::isValidRnc('1018A0043'))->toBeFalse()
        ->and(FiscalId::isValidRnc('10185004'))->toBeFalse();
});

test('rnc with dashes is normalised', function () {
    expect(FiscalId::normalize('1-01-85004-3'))->toBe('101850043')
        ->and(FiscalId::isValidRnc('1-01-85004-3'))->toBeTrue()
        ->and(FiscalId::format('131246796'))->toBe('1-31-24679-6');
});

test('cedula luhn validation', function () {
    expect(FiscalId::isValidCedula('00113918205'))->toBeTrue()
        ->and(FiscalId::isValidCedula('00113918204'))->toBeFalse()
        ->and(FiscalId::isValidCedula('0011391820A'))->toBeFalse()
        ->and(FiscalId::isValidCedula('0011391820'))->toBeFalse();
});

test('cedula with dashes is normalised', function () {
    expect(FiscalId::normalize('001-1391820-5'))->toBe('00113918205')
        ->and(FiscalId::isValidCedula('001-1391820-5'))->toBeTrue()
        ->and(FiscalId::format('22400022111'))->toBe('224-0002211-1');
});
