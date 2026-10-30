<?php

namespace App\Http\Requests\Sales;

use App\Enums\CreditNoteReason;
use App\Enums\InvoiceKind;
use App\Enums\InvoiceStatus;
use App\Http\Requests\Sales\Concerns\ValidatesDocumentLines;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Money\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCreditNoteRequest extends FormRequest
{
    use ValidatesDocumentLines;

    public function authorize(): bool
    {
        return $this->user()?->can('sales.credit-notes.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', Rule::enum(CreditNoteReason::class)],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:2000'],
            ...$this->lineRules(),
            'lines.*.invoice_line_id' => ['nullable', 'integer', 'distinct', 'exists:invoice_lines,id'],
            'lines.*.lot_id' => ['nullable', 'integer', 'exists:lots,id'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'warehouse_id.exists' => 'El almacén no existe o está inactivo.',
            'lines.*.invoice_line_id.distinct' => 'La línea de factura está repetida.',
            ...$this->lineMessages(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $invoiceId = $this->integer('invoice_id');
            $invoice = $invoiceId > 0 && ! $validator->errors()->has('invoice_id')
                ? Invoice::query()->with('lines.product')->whereKey($invoiceId)->first()
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
            if ($this->string('issue_date')->toString() < $invoice->issue_date->toDateString()) {
                $validator->errors()->add('issue_date', 'La nota no puede ser anterior a la factura.');
            }

            $reason = CreditNoteReason::tryFrom($this->string('reason')->toString());
            if ($invoice->kind === InvoiceKind::Historical && $reason !== null && $reason !== CreditNoteReason::Discount) {
                $validator->errors()->add('reason', 'Las facturas históricas solo admiten notas de descuento.');
            }

            $lines = $this->input('lines');
            foreach (is_array($lines) ? $lines : [] as $index => $row) {
                if (! is_array($row) || $validator->errors()->hasAny(array_map(fn (string $field) => "lines.{$index}.{$field}", ['product_id', 'invoice_line_id', 'quantity', 'unit_price', 'lot_id']))) {
                    continue; // rows that already failed field rules are not compared
                }
                if ($invoice->kind === InvoiceKind::Historical) {
                    if (! blank($row['invoice_line_id'] ?? null)) {
                        $validator->errors()->add("lines.{$index}.invoice_line_id", 'Línea de factura inválida.');
                    }

                    continue;
                }
                $this->validateSaleLine($validator, $invoice, $reason, $index, $row);
            }
        });
    }

    /** @param array<string, mixed> $row */
    private function validateSaleLine(Validator $validator, Invoice $invoice, ?CreditNoteReason $reason, int $index, array $row): void
    {
        $line = blank($row['invoice_line_id'] ?? null) ? null : $invoice->lines->firstWhere('id', (int) $row['invoice_line_id']);
        if (! $line instanceof InvoiceLine) {
            $validator->errors()->add("lines.{$index}.invoice_line_id", 'Línea de factura inválida.');

            return;
        }
        if ((int) ($row['product_id'] ?? 0) !== $line->product_id) {
            $validator->errors()->add("lines.{$index}.product_id", 'El producto no coincide con la línea facturada.');
        }
        if (Decimal::cmp((string) ($row['unit_price'] ?? '0'), $line->unit_price) > 0) {
            $validator->errors()->add("lines.{$index}.unit_price", "La nota no puede superar el precio facturado ({$line->unit_price}).");
        }

        $quantity = (string) ($row['quantity'] ?? '0');
        if ($reason === CreditNoteReason::Return) {
            $returnable = Decimal::qty(Decimal::of($line->dispatched_quantity)->minus(Decimal::of($line->returned_quantity)));
            if (Decimal::cmp($quantity, $returnable) > 0) {
                $validator->errors()->add("lines.{$index}.quantity", "Solo se pueden devolver unidades ya despachadas (despachadas {$line->dispatched_quantity}, devueltas {$line->returned_quantity}).");
            }
            if ($line->product->tracks_lots && blank($row['lot_id'] ?? null)) {
                $validator->errors()->add("lines.{$index}.lot_id", 'Indica el lote al que regresa la mercancía.');
            }
        } elseif ($reason === CreditNoteReason::Correction) {
            $pending = $line->remainingToDispatch();
            if (Decimal::cmp($quantity, $pending) > 0) {
                $validator->errors()->add("lines.{$index}.quantity", "Cantidad pendiente de despacho: {$pending}.");
            }
        } elseif ($reason === CreditNoteReason::Discount && Decimal::cmp($quantity, $line->quantity) > 0) {
            $validator->errors()->add("lines.{$index}.quantity", "La cantidad no puede superar la facturada ({$line->quantity}).");
        }
    }
}
