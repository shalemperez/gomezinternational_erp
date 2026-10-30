<?php

namespace App\Actions\Sales;

use App\Enums\InvoiceStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\User;
use App\Services\Accounting\AccountingEventRecorder;
use App\Services\Inventory\InventoryService;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

class CancelInvoice
{
    public function __construct(private readonly InventoryService $inventory, private readonly AccountingEventRecorder $events) {}

    public function handle(Invoice $invoice, User $actor, string $reason): Invoice
    {
        return DB::transaction(function () use ($invoice, $actor, $reason): Invoice {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->with(['lines', 'currency'])->firstOrFail();
            $hasDispatches = $invoice->lines->contains(fn (InvoiceLine $line) => ! Decimal::isZero($line->dispatched_quantity));
            if (! $invoice->isOpen() || ! Decimal::isZero($invoice->paid_amount) || ! Decimal::isZero($invoice->credited_amount) || ! Decimal::isZero($invoice->debited_amount) || $hasDispatches) {
                throw new DocumentStateException('Solo se pueden anular facturas sin cobros, notas ni despachos; usa una nota de crédito.');
            }

            foreach ($invoice->lines as $line) {
                foreach ($line->reservations()->whereNull('released_at')->get() as $reservation) {
                    $this->inventory->release($reservation);
                }
            }

            $invoice->forceFill([
                'status' => InvoiceStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'balance' => '0.00',
            ])->save();

            activity('invoices')->causedBy($actor)->performedOn($invoice)->event('cancelled')
                ->withProperties(['reason' => $reason])
                ->log("Factura {$invoice->number} anulada");

            $this->events->record('invoice_cancelled', $invoice, [
                'number' => $invoice->number, 'ncf' => $invoice->ncf, 'ncf_type' => $invoice->ncf_type?->value, 'client_id' => $invoice->client_id,
                'issue_date' => $invoice->issue_date->toDateString(), 'cancelled_on' => now()->toDateString(), 'reason' => $reason,
                'total' => $invoice->total, 'total_base' => $invoice->total_base, 'currency' => $invoice->currency->code, 'exchange_rate' => $invoice->exchange_rate,
            ], now());

            return $invoice;
        });
    }
}
