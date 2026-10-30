<?php

namespace App\Services\Sales;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\Money\Decimal;

class ClientAccount
{
    /** Σ balance × exchange rate of the client's open invoices, in DOP. */
    public static function outstandingBase(Client $client): string
    {
        return Decimal::sum(Invoice::query()->where('client_id', $client->id)
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid])->get()
            ->map(fn (Invoice $invoice) => Decimal::mul($invoice->balance, $invoice->exchange_rate, Decimal::MONEY)));
    }

    /** Credit limit minus the outstanding balance; null when the client has no credit limit. */
    public static function availableCredit(Client $client): ?string
    {
        if (Decimal::isZero($client->credit_limit)) {
            return null;
        }

        return Decimal::money(Decimal::of($client->credit_limit)->minus(Decimal::of(self::outstandingBase($client))));
    }
}
