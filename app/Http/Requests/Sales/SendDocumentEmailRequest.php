<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;

class SendDocumentEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['email'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['email'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000', 'not_regex:/<script/i'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'to.required' => 'Indica al menos un destinatario.',
        ];
    }
}
