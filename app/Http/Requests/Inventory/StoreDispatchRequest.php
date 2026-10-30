<?php

namespace App\Http\Requests\Inventory;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Lot;
use App\Support\Money\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDispatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.dispatches.create') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', 'exists:invoices,id'],
            'dispatch_date' => ['required', 'date_format:Y-m-d'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'vehicle' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.invoice_line_id' => ['required', 'integer', 'exists:invoice_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0', 'regex:/^\d{1,10}(\.\d{1,4})?$/'],
            'lines.*.lot_id' => ['nullable', 'integer', 'exists:lots,id'],
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
                $validator->errors()->add('invoice_id', 'Solo se despachan facturas emitidas');

                return;
            }

            $lines = $this->input('lines');
            $requestedByLine = [];
            $rowsByLine = [];
            foreach (is_array($lines) ? $lines : [] as $index => $row) {
                if (! is_array($row) || $validator->errors()->hasAny(["lines.{$index}.invoice_line_id", "lines.{$index}.quantity", "lines.{$index}.lot_id"])) {
                    continue; // rows that already failed field rules are not compared
                }
                $invoiceLineId = (int) $row['invoice_line_id'];
                $line = $invoice->lines->firstWhere('id', $invoiceLineId);
                if (! $line instanceof InvoiceLine) {
                    $validator->errors()->add("lines.{$index}.invoice_line_id", 'Línea de factura inválida.');

                    continue;
                }
                if (Decimal::isZero((string) $row['quantity'])) {
                    continue; // a line with quantity 0 is dropped on save
                }

                $requestedByLine[$invoiceLineId] = Decimal::sum([$requestedByLine[$invoiceLineId] ?? '0', (string) $row['quantity']], 4);
                $rowsByLine[$invoiceLineId][] = $index;
                $this->validateLot($validator, $line, $index, $row);
            }

            foreach ($requestedByLine as $invoiceLineId => $requested) {
                $pending = $invoice->lines->firstWhere('id', $invoiceLineId)?->remainingToDispatch() ?? '0.0000';
                if (Decimal::cmp($requested, $pending) > 0) {
                    foreach ($rowsByLine[$invoiceLineId] as $index) {
                        $validator->errors()->add("lines.{$index}.quantity", "Cantidad pendiente de despacho: {$pending}.");
                    }
                }
            }

            if ($requestedByLine === []) {
                $validator->errors()->add('lines', 'Indica al menos una cantidad a despachar.');
            }
        });
    }

    /** @param array<string, mixed> $row */
    private function validateLot(Validator $validator, InvoiceLine $line, int $index, array $row): void
    {
        if ($line->product->tracks_lots && blank($row['lot_id'] ?? null)) {
            $validator->errors()->add("lines.{$index}.lot_id", 'El producto controla lotes: indica el lote.');

            return;
        }
        if (blank($row['lot_id'] ?? null)) {
            return;
        }
        $lot = Lot::query()->whereKey((int) $row['lot_id'])->first();
        if ($lot === null || $lot->product_id !== $line->product_id) {
            $validator->errors()->add("lines.{$index}.lot_id", 'El lote no pertenece al producto.');
        }
    }
}
