<?php

namespace App\Http\Requests\Catalog;

use App\Enums\AdditionalTaxCalculationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAdditionalTaxRequest extends FormRequest
{
    /** @var list<string> */
    protected array $booleans = ['included_in_itbis_base', 'is_active'];

    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    protected function prepareForValidation(): void
    {
        foreach ($this->booleans as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
            'rate' => $this->input('rate') === '' ? null : $this->input('rate'),
            'amount' => $this->input('amount') === '' ? null : $this->input('amount'),
            'dgii_code' => $this->input('dgii_code') === '' ? null : $this->input('dgii_code'),
        ]);
        if ($this->input('calculation_type') === 'percent_of_base') {
            $this->merge(['amount' => null]);
        } elseif ($this->filled('calculation_type')) {
            $this->merge(['rate' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'alpha_dash', 'unique:additional_taxes,code'],
            'name' => ['required', 'string', 'max:100'],
            'calculation_type' => ['required', Rule::enum(AdditionalTaxCalculationType::class)],
            'rate' => ['nullable', 'numeric', 'between:0,100', 'required_if:calculation_type,percent_of_base', 'regex:/^\d{1,3}(\.\d{1,4})?$/'],
            'amount' => ['nullable', 'numeric', 'min:0', 'required_unless:calculation_type,percent_of_base', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'included_in_itbis_base' => ['boolean'],
            'dgii_code' => ['nullable', 'string', 'regex:/^\d{3}$/'],
            'is_active' => ['boolean'],
        ];
    }
}
