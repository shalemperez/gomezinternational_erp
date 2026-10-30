<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'expired_override' => ['nullable', 'boolean'],
            'expired_override_reason' => ['nullable', 'string', 'max:255', 'required_if_accepted:expired_override'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'expired_override_reason.required_if_accepted' => 'Indica el motivo de la autorización.',
        ];
    }
}
