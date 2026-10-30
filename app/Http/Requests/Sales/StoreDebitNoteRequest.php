<?php

namespace App\Http\Requests\Sales;

use App\Enums\DebitNoteReason;
use App\Enums\InvoiceStatus;
use App\Http\Requests\Sales\Concerns\ValidatesDocumentLines;
use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreDebitNoteRequest extends FormRequest
{
    use ValidatesDocumentLines;

    public function authorize(): bool
    {
        return $this->user()?->can('sales.debit-notes.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'reason' => ['required', Rule::enum(DebitNoteReason::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->lineRules(activeProductsOnly: false),
            'lines.*.unit_price' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $invoiceId = $this->integer('invoice_id');
            $invoice = $invoiceId > 0 && ! $validator->errors()->has('invoice_id')
                ? Invoice::query()->whereKey($invoiceId)->first()
                : null;
            if ($invoice === null) {
                return;
            }
            if (! in_array($invoice->status, [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)) {
                $validator->errors()->add('invoice_id', 'La factura debe estar emitida.');

                return;
            }
            if (! Gate::allows('view', $invoice)) {
                $validator->errors()->add('invoice_id', 'No puedes emitir notas sobre facturas de otros vendedores.');

                return;
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->lineMessages();
    }
}
