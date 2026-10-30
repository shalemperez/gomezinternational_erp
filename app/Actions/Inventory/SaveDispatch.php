<?php

namespace App\Actions\Inventory;

use App\Enums\DispatchStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Dispatch;
use App\Models\DispatchLine;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveDispatch
{
    /** @param array<string, mixed> $data */
    public function handle(array $data, Invoice $invoice, User $actor, ?Dispatch $dispatch = null): Dispatch
    {
        return DB::transaction(function () use ($data, $invoice, $actor, $dispatch): Dispatch {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->with('lines')->firstOrFail();
            $rows = array_values(array_filter($data['lines'], fn (array $row) => Decimal::cmp((string) $row['quantity'], '0') > 0));
            $requestedByLine = [];
            foreach ($rows as $row) {
                $invoiceLineId = (int) $row['invoice_line_id'];
                $requestedByLine[$invoiceLineId] = Decimal::sum([$requestedByLine[$invoiceLineId] ?? '0', (string) $row['quantity']], 4);
            }
            foreach ($requestedByLine as $invoiceLineId => $requested) {
                $pending = $invoice->lines->firstWhere('id', $invoiceLineId)?->remainingToDispatch() ?? '0.0000';
                if (Decimal::cmp($requested, $pending) > 0) {
                    throw ValidationException::withMessages(['lines' => "Cantidad pendiente de despacho: {$pending}."]);
                }
            }
            $header = [
                'invoice_id' => $invoice->id, 'client_id' => $invoice->client_id, 'warehouse_id' => $invoice->warehouse_id,
                'dispatch_date' => $data['dispatch_date'], 'driver_name' => $data['driver_name'] ?? null,
                'vehicle' => $data['vehicle'] ?? null, 'notes' => $data['notes'] ?? null,
            ];

            if ($dispatch === null) {
                $dispatch = Dispatch::query()->create([...$header, 'status' => DispatchStatus::Draft, 'created_by' => $actor->id]);
            } else {
                $dispatch = Dispatch::query()->whereKey($dispatch->id)->lockForUpdate()->firstOrFail();
                if ($dispatch->status !== DispatchStatus::Draft) {
                    throw new DocumentStateException('Solo se pueden editar conduces en borrador');
                }
                $dispatch->forceFill($header)->save();
                $dispatch->lines()->get()->each->delete();
            }

            foreach ($rows as $row) {
                $invoiceLine = $invoice->lines->firstWhere('id', (int) $row['invoice_line_id']);
                DispatchLine::query()->create([
                    'dispatch_id' => $dispatch->id, 'invoice_line_id' => $invoiceLine->id, 'product_id' => $invoiceLine->product_id,
                    'lot_id' => blank($row['lot_id'] ?? null) ? null : (int) $row['lot_id'], 'quantity' => Decimal::qty((string) $row['quantity']),
                ]);
            }

            return $dispatch->fresh();
        });
    }
}
