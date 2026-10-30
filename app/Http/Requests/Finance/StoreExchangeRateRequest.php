<?php

namespace App\Http\Requests\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExchangeRateRequest extends FormRequest
{
    /** @var list<string> */
    protected array $booleans = [];

    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    protected function prepareForValidation(): void
    {
        foreach ($this->booleans as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'currency_id' => ['required', Rule::exists('currencies', 'id')->where('is_base', false)],
            'rate_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'rate' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,6}(\.\d{1,6})?$/'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
