<?php

namespace App\Services\Sales;

use App\Enums\InvoiceStatus;
use App\Enums\ReceiptStatus;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\Receipt;
use App\Support\Money\Decimal;

class ClientAccount
{
    /** Σ unapplied_amount of the client's posted receipts and issued credit notes in one currency (the client's advances and credits). */
    public static function advanceBalance(Client $client, Currency $currency): string
    {
        return Decimal::sum([
            Decimal::sum(Receipt::query()->where('client_id', $client->id)->where('currency_id', $currency->id)
                ->where('status', ReceiptStatus::Posted)->pluck('unapplied_amount')),
            Decimal::sum(CreditNote::query()->where('client_id', $client->id)->where('currency_id', $currency->id)
                ->where('status', 'issued')->pluck('unapplied_amount')),
        ]);
    }

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
