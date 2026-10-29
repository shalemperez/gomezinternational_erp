<?php

namespace App\Rules;

use App\Support\FiscalId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DominicanTaxId implements ValidationRule
{
    public function __construct(private readonly string $type) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;
        if ($this->type === 'rnc' && ! FiscalId::isValidRnc($value)) {
            $fail('El RNC debe tener 9 dígitos y un dígito verificador válido.');
        }
        if ($this->type === 'cedula' && ! FiscalId::isValidCedula($value)) {
            $fail('La cédula debe tener 11 dígitos y un dígito verificador válido.');
        }
    }
}
