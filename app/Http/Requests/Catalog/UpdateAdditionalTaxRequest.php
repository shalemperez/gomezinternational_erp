<?php

namespace App\Http\Requests\Catalog;

use App\Enums\AdditionalTaxCalculationType;
use Illuminate\Validation\Rule;

class UpdateAdditionalTaxRequest extends StoreAdditionalTaxRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('additional_taxes', 'code')->ignore($this->route('additionalTax'))],
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
