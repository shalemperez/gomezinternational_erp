<?php

namespace App\Http\Requests\Sales;

use App\Enums\ReceiptStatus;
use App\Http\Requests\Sales\Concerns\ValidatesReceiptApplications;
use App\Models\CreditNote;
use App\Models\Receipt;
use App\Support\Money\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ApplyAdvanceRequest extends FormRequest
{
    use ValidatesReceiptApplications;

    public function authorize(): bool
    {
        return $this->user()?->can('sales.receipts.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'applications' => ['required', 'array', 'min:1', 'max:100'],
            ...$this->applicationRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['applications.*.amount.regex' => 'Usa como máximo dos decimales.'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $source = $this->route('receipt') ?? $this->route('creditNote');
            if ($validator->errors()->isNotEmpty() || (! $source instanceof Receipt && ! $source instanceof CreditNote)) {
                return;
            }

            $applied = $this->checkApplications($validator, $this->applicationInvoices(), (int) $source->client_id, (int) $source->currency_id);
            // A cancelled receipt or a credit note without credit is refused by the action with its own message.
            $hasAdvance = $source instanceof Receipt
                ? $source->status !== ReceiptStatus::Cancelled && ! Decimal::isZero($source->unapplied_amount)
                : $source->status === 'issued' && ! Decimal::isZero($source->unapplied_amount);
            if ($hasAdvance && Decimal::cmp($applied, $source->unapplied_amount) > 0) {
                $validator->errors()->add('applications', 'Las aplicaciones ('.Decimal::money($applied).') superan el '.($source instanceof Receipt ? 'anticipo' : 'saldo a favor').' disponible ('.Decimal::money($source->unapplied_amount).').');
            }
        });
    }
}
