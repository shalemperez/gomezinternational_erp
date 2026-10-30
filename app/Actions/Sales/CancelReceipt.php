<?php

namespace App\Actions\Sales;

use App\Enums\ReceiptStatus;
use App\Exceptions\DocumentStateException;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Models\User;
use App\Services\Accounting\AccountingEventRecorder;
use App\Support\Money\Decimal;
use Illuminate\Support\Facades\DB;

class CancelReceipt
{
    public function __construct(private readonly AccountingEventRecorder $events) {}

    public function handle(Receipt $receipt, User $actor, string $reason): Receipt
    {
        return DB::transaction(function () use ($receipt, $actor, $reason): Receipt {
            $receipt = Receipt::query()->whereKey($receipt->id)->lockForUpdate()->with(['applications', 'currency'])->firstOrFail();
            if ($receipt->status === ReceiptStatus::Cancelled) {
                throw new DocumentStateException('El recibo ya está anulado');
            }

            $invoiceIds = $receipt->applications->pluck('invoice_id')->unique()->sort()->values()->all();
            $invoices = Invoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $reversed = [];
            foreach ($receipt->applications as $application) {
                $invoice = $invoices->get($application->invoice_id) ?? throw new DocumentStateException('La factura ya no existe');
                $invoice->forceFill(['paid_amount' => Decimal::money(Decimal::of($invoice->paid_amount)->minus($application->amount))])->save();
                $invoice->refreshFinancials();

                activity('invoices')->causedBy($actor)->performedOn($invoice)->event('payment_reversed')
                    ->withProperties(['receipt_id' => $receipt->id, 'number' => $receipt->number, 'amount' => $application->amount])
                    ->log("Cobro {$receipt->number} anulado en la factura {$invoice->number}");
                $reversed[] = ['invoice_id' => $invoice->id, 'amount' => $application->amount];
            }

            $appliedBefore = $receipt->applied_total;
            $receipt->forceFill([
                'status' => ReceiptStatus::Cancelled,
                'applied_total' => '0.00',
                'unapplied_amount' => '0.00',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
            ])->save();

            activity('receipts')->causedBy($actor)->performedOn($receipt)->event('cancelled')
                ->withProperties(['reason' => $reason])
                ->log("Recibo {$receipt->number} anulado");

            $this->events->record('receipt_cancelled', $receipt, [
                'number' => $receipt->number, 'client_id' => $receipt->client_id, 'reason' => $reason, 'reversed' => $reversed,
                'amount_received' => $receipt->amount_received, 'currency' => $receipt->currency->code, 'exchange_rate' => $receipt->exchange_rate,
                'retention_itbis' => $receipt->retention_itbis, 'retention_isr' => $receipt->retention_isr, 'applied_total' => $appliedBefore,
            ], now());

            return $receipt->fresh();
        });
    }
}
