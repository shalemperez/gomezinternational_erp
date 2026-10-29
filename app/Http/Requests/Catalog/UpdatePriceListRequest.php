<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Validation\Rule;

class UpdatePriceListRequest extends StorePriceListRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('price_lists', 'name')->ignore($this->route('priceList'))],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'is_default' => ['boolean', 'declined_if:is_active,false'],
            'is_active' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'is_default.declined_if' => 'La lista predeterminada debe estar activa.',
        ];
    }
}
