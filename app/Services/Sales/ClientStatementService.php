<?php

namespace App\Services\Sales;

use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\ReceiptApplication;
use App\Models\User;
use App\Support\Money\Decimal;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @phpstan-type Movement array{date: string, id: int, order: int, currency: string, type: string, type_label: string, number: ?string, ncf: ?string, description: string, debit: string, credit: string}
 * @phpstan-type AgingItem array{invoice: Invoice, bucket: string, balance: string, base: string}
 */
class ClientStatementService
{
    /**
     * Movements of one client per currency with a running balance inside the period.
     *
     * @return array<string, array{opening_balance: string, rows: list<array{date: string, type: string, type_label: string, number: ?string, ncf: ?string, description: string, debit: string, credit: string, balance: string}>, closing_balance: string}>
     */
    public function statement(Client $client, CarbonInterface $from, CarbonInterface $to): array
    {
        $movements = $this->movements($client)
            ->filter(fn (array $movement) => $movement['date'] <= $to->toDateString())
            ->sortBy([['date', 'asc'], ['order', 'asc'], ['id', 'asc']])
            ->groupBy('currency');

        $fromDate = $from->toDateString();
        $statement = [];
        foreach ($movements as $currency => $items) {
            $opening = $this->net($items->filter(fn (array $movement) => $movement['date'] < $fromDate));
            $balance = $opening;
            $rows = [];
            foreach ($items->filter(fn (array $movement) => $movement['date'] >= $fromDate)->values() as $movement) {
                $balance = Decimal::money(Decimal::of($balance)->plus($movement['debit'])->minus($movement['credit']));
                $rows[] = [
                    'date' => $movement['date'], 'type' => $movement['type'], 'type_label' => $movement['type_label'],
                    'number' => $movement['number'], 'ncf' => $movement['ncf'], 'description' => $movement['description'],
                    'debit' => $movement['debit'], 'credit' => $movement['credit'], 'balance' => $balance,
                ];
            }
            $statement[(string) $currency] = ['opening_balance' => $opening, 'rows' => $rows, 'closing_balance' => $balance];
        }

        return $statement;
    }

    /**
     * Open balances of issued invoices bucketed by days overdue at $asOf, per client and currency.
     *
     * @return array{rows: list<array{client_id: int, client_code: string, client_name: string, salesperson: ?string, currency_code: string, current: string, d1_30: string, d31_60: string, d61_90: string, d90_plus: string, total: string, total_base: string}>, totals: array{current: string, d1_30: string, d61_90: string, d31_60: string, d90_plus: string, total: string, total_base: string}}
     */
    public function aging(User $viewer, CarbonInterface $asOf, ?int $salespersonId = null, ?string $search = null, ?int $clientId = null): array
    {
        $asOfDate = $asOf->toDateString();
        $invoices = Invoice::query()
            ->whereNotNull('issued_at')->whereDate('issue_date', '<=', $asOfDate)
            ->where(fn (Builder $query) => $query->whereNot('status', InvoiceStatus::Cancelled)->orWhereDate('cancelled_at', '>', $asOfDate))
            ->when($clientId !== null, fn (Builder $query) => $query->where('client_id', $clientId))
            ->whereHas('client', fn (Builder $query) => $query->visibleTo($viewer)
                ->when($salespersonId !== null, fn (Builder $q) => $q->where('salesperson_id', $salespersonId))
                ->when($search !== null && $search !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                    ->where('business_name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"))))
            ->with(['client.salesperson:id,name', 'currency:id,code'])
            ->get();

        $invoiceIds = $invoices->pluck('id')->all();
        $debits = DebitNote::query()->whereIn('invoice_id', $invoiceIds)->where('status', 'issued')->whereDate('issue_date', '<=', $asOfDate)
            ->selectRaw('invoice_id, SUM(total) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $credits = CreditNote::query()->whereIn('invoice_id', $invoiceIds)->where('status', 'issued')->whereDate('issue_date', '<=', $asOfDate)
            ->selectRaw('invoice_id, SUM(applied_amount) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');
        $payments = ReceiptApplication::query()->whereIn('invoice_id', $invoiceIds)->whereDate('applied_on', '<=', $asOfDate)
            ->where(fn (Builder $query) => $query->whereNotNull('credit_note_id')->orWhereHas('receipt', fn (Builder $receipts) => $receipts->where('status', ReceiptStatus::Posted)))
            ->selectRaw('invoice_id, SUM(amount) AS amount')->groupBy('invoice_id')->pluck('amount', 'invoice_id');

        $invoices = $invoices->map(function (Invoice $invoice) use ($asOf, $debits, $credits, $payments): array {
            $balance = Decimal::money(Decimal::of($invoice->total)
                ->plus(Decimal::of((string) ($debits[$invoice->id] ?? '0')))
                ->minus(Decimal::of((string) ($credits[$invoice->id] ?? '0')))
                ->minus(Decimal::of((string) ($payments[$invoice->id] ?? '0')))
                ->minus(Decimal::of($invoice->prior_paid_amount)));
            $days = (int) $invoice->due_date->diffInDays($asOf, false);

            return [
                'invoice' => $invoice,
                'bucket' => match (true) {
                    $days <= 0 => 'current',
                    $days <= 30 => 'd1_30',
                    $days <= 60 => 'd31_60',
                    $days <= 90 => 'd61_90',
                    default => 'd90_plus',
                },
                'balance' => $balance,
                'base' => Decimal::mul($balance, $invoice->exchange_rate, Decimal::MONEY),
            ];
        })->filter(fn (array $item) => Decimal::cmp($item['balance'], '0') > 0)->values();

        $rows = $invoices->groupBy(fn (array $item) => $item['invoice']->client_id.'|'.$item['invoice']->currency->code)
            ->map(function (Collection $items): array {
                /** @var Invoice $first */
                $first = $items->first()['invoice'];

                return [
                    'client_id' => $first->client_id,
                    'client_code' => $first->client->code,
                    'client_name' => $first->client->business_name,
                    'salesperson' => $first->client->salesperson?->name,
                    'currency_code' => $first->currency->code,
                    'current' => $this->bucketSum($items, 'current', 'balance'),
                    'd1_30' => $this->bucketSum($items, 'd1_30', 'balance'),
                    'd31_60' => $this->bucketSum($items, 'd31_60', 'balance'),
                    'd61_90' => $this->bucketSum($items, 'd61_90', 'balance'),
                    'd90_plus' => $this->bucketSum($items, 'd90_plus', 'balance'),
                    'total' => Decimal::sum($items->pluck('balance')),
                    'total_base' => Decimal::sum($items->pluck('base')),
                ];
            })
            ->sortBy([['client_name', 'asc'], ['currency_code', 'asc']])
            ->all();
        $rows = array_values($rows);

        return [
            'rows' => $rows,
            'totals' => [
                'current' => $this->bucketSum($invoices, 'current', 'base'),
                'd1_30' => $this->bucketSum($invoices, 'd1_30', 'base'),
                'd61_90' => $this->bucketSum($invoices, 'd61_90', 'base'),
                'd31_60' => $this->bucketSum($invoices, 'd31_60', 'base'),
                'd90_plus' => $this->bucketSum($invoices, 'd90_plus', 'base'),
                'total' => Decimal::sum($invoices->pluck('base')),
                'total_base' => Decimal::sum($invoices->pluck('base')),
            ],
        ];
    }

    /** @return Collection<int, Movement> */
    private function movements(Client $client): Collection
    {
        $clientInvoiceIds = Invoice::query()->where('client_id', $client->id)->select('id');

        $invoices = Invoice::query()->where('client_id', $client->id)->whereNotNull('issued_at')
            ->whereNot('status', InvoiceStatus::Cancelled)->with('currency:id,code')->get()
            ->map(fn (Invoice $invoice) => [
                'date' => $invoice->issue_date->toDateString(), 'id' => $invoice->id, 'order' => 0, 'currency' => $invoice->currency->code,
                'type' => 'invoice', 'type_label' => 'Factura', 'number' => $invoice->number, 'ncf' => $invoice->ncf,
                'description' => $invoice->kind->label(), 'debit' => Decimal::money($invoice->total), 'credit' => Decimal::money('0'),
            ]);

        $priorPayments = Invoice::query()->where('client_id', $client->id)->whereNotNull('issued_at')->whereNot('status', InvoiceStatus::Cancelled)
            ->where('prior_paid_amount', '>', 0)->with('currency:id,code')->get()
            ->map(fn (Invoice $invoice) => [
                'date' => $invoice->issue_date->toDateString(), 'id' => $invoice->id, 'order' => 3, 'currency' => $invoice->currency->code,
                'type' => 'prior_payment', 'type_label' => 'Pagos anteriores', 'number' => $invoice->number, 'ncf' => $invoice->ncf,
                'description' => 'Pagos recibidos antes del sistema', 'debit' => Decimal::money('0'), 'credit' => Decimal::money($invoice->prior_paid_amount),
            ]);

        $debitNotes = DebitNote::query()->whereIn('invoice_id', $clientInvoiceIds)->where('status', 'issued')
            ->with(['currency:id,code', 'invoice:id,number'])->get()
            ->map(fn (DebitNote $note) => [
                'date' => $note->issue_date->toDateString(), 'id' => $note->id, 'order' => 1, 'currency' => $note->currency->code,
                'type' => 'debit_note', 'type_label' => 'Nota de débito', 'number' => $note->number, 'ncf' => $note->ncf,
                'description' => 'Sobre factura '.$note->invoice->number, 'debit' => Decimal::money($note->total), 'credit' => Decimal::money('0'),
            ]);

        $creditNotes = CreditNote::query()->whereIn('invoice_id', $clientInvoiceIds)->where('status', 'issued')
            ->with(['currency:id,code', 'invoice:id,number'])->get()
            ->map(fn (CreditNote $note) => [
                'date' => $note->issue_date->toDateString(), 'id' => $note->id, 'order' => 2, 'currency' => $note->currency->code,
                'type' => 'credit_note', 'type_label' => 'Nota de crédito', 'number' => $note->number, 'ncf' => $note->ncf,
                'description' => 'Sobre factura '.$note->invoice->number, 'debit' => Decimal::money('0'), 'credit' => Decimal::money($note->total),
            ]);

        $payments = ReceiptApplication::query()->whereNotNull('receipt_id')
            ->whereHas('receipt', fn (Builder $query) => $query->where('client_id', $client->id)->where('status', ReceiptStatus::Posted))
            ->with(['receipt.currency:id,code', 'receipt:id,number,receipt_date,currency_id', 'invoice:id,number'])->get()
            ->map(fn (ReceiptApplication $application) => [
                'date' => $application->receipt->receipt_date->toDateString(), 'id' => $application->id, 'order' => 4, 'currency' => $application->receipt->currency->code,
                'type' => 'payment', 'type_label' => 'Cobro', 'number' => $application->receipt->number, 'ncf' => null,
                'description' => 'Aplicado a factura '.$application->invoice->number, 'debit' => Decimal::money('0'), 'credit' => Decimal::money($application->amount),
            ]);

        return $invoices->concat($priorPayments)->concat($debitNotes)->concat($creditNotes)->concat($payments)->values();
    }

    /** @param Collection<int, Movement> $movements */
    private function net(Collection $movements): string
    {
        return Decimal::money(Decimal::of(Decimal::sum($movements->pluck('debit')))->minus(Decimal::sum($movements->pluck('credit'))));
    }

    /** @param Collection<int, AgingItem> $items */
    private function bucketSum(Collection $items, string $bucket, string $amountKey): string
    {
        return Decimal::sum($items->filter(fn (array $item) => $item['bucket'] === $bucket)->pluck($amountKey));
    }
}
