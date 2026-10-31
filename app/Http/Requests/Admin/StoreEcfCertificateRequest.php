<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEcfCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'certificate' => ['required', Rule::file()->extensions(['p12', 'pfx'])->max(64)],
            'password' => ['required', 'string', 'max:200'],
            'name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'certificate' => 'el certificado', 'password' => 'la contraseña del certificado', 'name' => 'el nombre',
        ];
    }
}
