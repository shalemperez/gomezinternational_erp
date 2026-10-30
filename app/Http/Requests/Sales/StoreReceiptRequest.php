<?php

namespace App\Http\Requests\Sales;

use App\Http\Requests\Sales\Concerns\ValidatesReceiptApplications;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Support\Money\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreReceiptRequest extends FormRequest
{
    use ValidatesReceiptApplications;

    public function authorize(): bool
    {
        return $this->user()?->can('sales.receipts.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'financial_account_id' => $this->filled('financial_account_id') ? $this->input('financial_account_id') : null,
            'reference' => $this->filled('reference') ? $this->input('reference') : null,
            'notes' => $this->filled('notes') ? $this->input('notes') : null,
            'retention_itbis' => $this->filled('retention_itbis') ? $this->input('retention_itbis') : '0',
            'retention_isr' => $this->filled('retention_isr') ? $this->input('retention_isr') : '0',
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->where('is_active', true)],
            'receipt_date' => ['required', 'date_format:Y-m-d'],
            'payment_method' => ['required', Rule::in(['transfer', 'check', 'cash', 'card'])],
            'financial_account_id' => ['required', 'integer', Rule::exists('financial_accounts', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:100'],
            'amount_received' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'retention_itbis' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'retention_isr' => ['required', 'numeric', 'min:0', 'max:999999999999.99', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'applications' => ['present', 'array', 'max:100'],
            ...$this->applicationRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $retentionItbis = $this->string('retention_itbis')->toString();
            $retentionIsr = $this->string('retention_isr')->toString();
            $credit = Decimal::sum([$this->string('amount_received')->toString(), $retentionItbis, $retentionIsr]);
            if (Decimal::isZero($credit)) {
                $validator->errors()->add('amount_received', 'El recibo debe tener un monto o una retención.');
            }

            $account = FinancialAccount::query()->whereKey($this->integer('financial_account_id'))->first();
            $currencyId = $this->integer('currency_id');
            if ($account !== null && (int) $account->currency_id !== $currencyId) {
                $validator->errors()->add('financial_account_id', 'La cuenta está en otra moneda.');
            }

            $invoices = $this->applicationInvoices();
            $applied = $this->checkApplications($validator, $invoices, $this->integer('client_id'), $currencyId);
            if (Decimal::cmp($applied, $credit) > 0) {
                $validator->errors()->add('applications', 'Las aplicaciones ('.Decimal::money($applied).') superan el monto del recibo ('.Decimal::money($credit).').');
            }

            $itbis = Decimal::sum($invoices->map(fn (Invoice $invoice) => $invoice->itbis_total));
            if (Decimal::cmp($retentionItbis, $itbis) > 0) {
                $validator->errors()->add('retention_itbis', 'La retención de ITBIS supera el ITBIS de las facturas aplicadas.');
            }

            $taxable = Decimal::sum($invoices->map(fn (Invoice $invoice) => $invoice->taxable_total));
            if (Decimal::cmp($retentionIsr, $taxable) > 0) {
                $validator->errors()->add('retention_isr', 'La retención de ISR supera la base de las facturas aplicadas.');
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'financial_account_id.exists' => 'La cuenta no existe o está inactiva.',
            'amount_received.regex' => 'Usa como máximo dos decimales.',
            'retention_itbis.regex' => 'Usa como máximo dos decimales.',
            'retention_isr.regex' => 'Usa como máximo dos decimales.',
            'applications.*.amount.regex' => 'Usa como máximo dos decimales.',
        ];
    }
}
