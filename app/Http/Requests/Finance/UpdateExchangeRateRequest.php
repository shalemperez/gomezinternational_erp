<?php

namespace App\Http\Requests\Finance;

class UpdateExchangeRateRequest extends StoreExchangeRateRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'rate' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,6}(\.\d{1,6})?$/'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
