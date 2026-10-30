<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TotalsPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:500'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'lines.*.description' => ['nullable', 'string', 'max:200'],
            'lines.*.itbis_rate' => ['nullable', Rule::in(['0.00', '16.00', '18.00'])],
        ];
    }
}
