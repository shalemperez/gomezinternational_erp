<?php

namespace App\Http\Requests\Fiscal;

use Illuminate\Foundation\Http\FormRequest;

class CommercialApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('fiscal.received-ecf.approve') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'in:approve,reject'],
            'reason' => ['required_if:decision,reject', 'nullable', 'string', 'max:250'],
        ];
    }
}
