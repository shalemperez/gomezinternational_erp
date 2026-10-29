<?php

namespace App\Http\Requests\Finance;

use App\Enums\FinancialAccountType;
use Illuminate\Validation\Rule;

class UpdateFinancialAccountRequest extends StoreFinancialAccountRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(FinancialAccountType::class)],
            'name' => ['required', 'string', 'max:100'],
            'bank_name' => ['required_if:type,bank', 'nullable', 'string', 'max:100'],
            'account_number' => ['required_if:type,bank', 'nullable', 'string', 'max:50', Rule::unique('financial_accounts', 'account_number')->where('bank_name', $this->input('bank_name'))->ignore($this->route('financialAccount'))],
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'is_active' => ['boolean'],
        ];
    }
}
