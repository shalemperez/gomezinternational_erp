<?php

namespace App\Http\Requests\Concerns;

use App\Rules\DominicanTaxId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared RNC/cédula validation for clients and suppliers: checksum by type, uniqueness,
 * the audited override (permission catalog.tax-ids.override-validation + mandatory reason),
 * and, when a country field is given, the rule that a Dominican tax id only applies to local records.
 */
trait ValidatesDominicanTaxId
{
    protected function addTaxIdValidation(Validator $validator, string $table, ?Model $ignore, string $taxIdField = 'tax_id', string $typeField = 'tax_id_type', ?string $countryField = null): void
    {
        $type = (string) $this->input($typeField);
        $taxId = (string) $this->input($taxIdField);
        $override = $this->boolean('tax_id_override');
        $isLocal = $countryField === null || $this->input($countryField) === 'DO';
        $keepsOverride = ! $override && $ignore !== null
            && (bool) $ignore->getAttribute('tax_id_validation_overridden')
            && preg_replace('/\D/', '', $taxId) === (string) $ignore->getAttribute($taxIdField);

        if ($override && ! $this->user()->can('catalog.tax-ids.override-validation')) {
            throw new AuthorizationException('No tienes permiso para forzar la identificación fiscal.');
        }

        $validator->sometimes($taxIdField, [Rule::unique($table, $taxIdField)->ignore($ignore)], fn () => $taxId !== '');

        if ($countryField !== null && ! $isLocal) {
            $validator->sometimes($taxIdField, ['prohibited'], fn () => $taxId !== '');
            $validator->after(function (Validator $v) use ($taxIdField, $taxId): void {
                if ($taxId !== '') {
                    $v->errors()->forget($taxIdField);
                    $v->errors()->add($taxIdField, 'El RNC/cédula solo aplica a proveedores locales.');
                }
            });

            return;
        }

        if (in_array($type, ['rnc', 'cedula'], true) && $taxId !== '') {
            $digits = $type === 'rnc' ? 9 : 11;
            $validator->sometimes($taxIdField, ($override || $keepsOverride) ? ["digits:{$digits}"] : [new DominicanTaxId($type)], fn () => true);
        }

        $validator->sometimes('tax_id_override_reason', ['required', 'string', 'max:255'], fn () => $override);
    }

    /** @return array<string, string> */
    protected function taxIdMessages(): array
    {
        return ['tax_id_override_reason.required' => 'Indica el motivo para forzar la identificación fiscal.'];
    }
}
