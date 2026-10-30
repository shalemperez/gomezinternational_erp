<?php

namespace App\Http\Requests\Finance;

use App\Models\Currency;
use Illuminate\Validation\Validator;

class UpdateCurrencyRequest extends StoreCurrencyRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
            'symbol' => ['required', 'string', 'max:5'],
            'decimal_places' => ['required', 'integer', 'between:0,4'],
            'is_active' => ['boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var Currency $currency */
            $currency = $this->route('currency');
            if ($currency->is_base && $this->boolean('is_active') === false) {
                $v->errors()->add('is_active', 'La moneda base no se puede desactivar.');
            }
        });
    }
}
