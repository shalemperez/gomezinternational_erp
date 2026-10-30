<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class IssueInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'credit_override' => ['nullable', 'boolean'],
            'credit_override_reason' => ['nullable', 'string', 'max:255', 'required_if:credit_override,1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'credit_override_reason.required_if' => 'Indica el motivo de la autorización.',
        ];
    }
}
