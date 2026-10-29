<?php

use App\Rules\DominicanTaxId;
use Illuminate\Support\Facades\Validator;

test('rule validates by type with spanish messages', function () {
    expect(Validator::make(['v' => '101850043'], ['v' => [new DominicanTaxId('rnc')]])->passes())->toBeTrue()
        ->and(Validator::make(['v' => '101850042'], ['v' => [new DominicanTaxId('rnc')]])->errors()->first('v'))->toBe('El RNC debe tener 9 dígitos y un dígito verificador válido.')
        ->and(Validator::make(['v' => '00113918205'], ['v' => [new DominicanTaxId('cedula')]])->passes())->toBeTrue()
        ->and(Validator::make(['v' => '00113918204'], ['v' => [new DominicanTaxId('cedula')]])->errors()->first('v'))->toBe('La cédula debe tener 11 dígitos y un dígito verificador válido.');
});
