<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            'body_html' => ['required', 'string', 'max:20000', 'regex:/^(?!.*<script).*$/is'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'body_html.regex' => 'La plantilla no puede contener scripts.',
        ];
    }
}
