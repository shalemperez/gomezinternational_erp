<?php

namespace App\Http\Requests\Finance;

use App\Enums\FinancialAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialAccountRequest extends FormRequest
{
    /** @var list<string> */
    protected array $booleans = ['is_active'];

    public function authorize(): bool
    {
        return true; // permission enforced by controller middleware
    }

    protected function prepareForValidation(): void
    {
        foreach ($this->booleans as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
        foreach (['bank_name', 'account_number'] as $field) {
            if ($this->input($field) === '') {
                $this->merge([$field => null]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(FinancialAccountType::class)],
            'name' => ['required', 'string', 'max:100'],
            'bank_name' => ['required_if:type,bank', 'nullable', 'string', 'max:100'],
            'account_number' => ['required_if:type,bank', 'nullable', 'string', 'max:50', Rule::unique('financial_accounts', 'account_number')->where('bank_name', $this->input('bank_name'))],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'is_active' => ['boolean'],
        ];
    }
}
