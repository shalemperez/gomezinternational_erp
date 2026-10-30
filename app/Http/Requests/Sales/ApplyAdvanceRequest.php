<?php

namespace App\Http\Requests\Sales;

use App\Enums\ReceiptStatus;
use App\Http\Requests\Sales\Concerns\ValidatesReceiptApplications;
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $receipt = $this->route('receipt');
            if ($validator->errors()->isNotEmpty() || ! $receipt instanceof Receipt) {
                return;
            }

            $applied = $this->checkApplications($validator, $this->applicationInvoices(), (int) $receipt->client_id, (int) $receipt->currency_id);
            // A cancelled receipt or one without advance is refused by the action with its own message.
            $hasAdvance = $receipt->status !== ReceiptStatus::Cancelled && ! Decimal::isZero($receipt->unapplied_amount);
            if ($hasAdvance && Decimal::cmp($applied, $receipt->unapplied_amount) > 0) {
                $validator->errors()->add('applications', 'Las aplicaciones ('.Decimal::money($applied).') superan el anticipo disponible ('.Decimal::money($receipt->unapplied_amount).').');
            }
        });
    }
}
