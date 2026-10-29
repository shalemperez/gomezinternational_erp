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
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }
}
