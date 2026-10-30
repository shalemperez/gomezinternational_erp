<?php

namespace App\Services\Accounting;

use App\Models\AccountingEvent;
use App\Models\Currency;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

class AccountingEventRecorder
{
    /** @param array<string, mixed> $payload must include 'currency' (code) and 'exchange_rate' (6-dp string) */
    public function record(string $type, Model $document, array $payload, ?CarbonInterface $occurredOn = null): AccountingEvent
    {
        if (! isset($payload['currency'], $payload['exchange_rate'])) {
            throw new InvalidArgumentException('El evento contable requiere currency y exchange_rate');
        }
        $currency = Currency::query()->where('code', $payload['currency'])->firstOrFail();

        return AccountingEvent::create([
            'type' => $type,
            'document_type' => $document::class,
            'document_id' => $document->getKey(),
            'occurred_on' => ($occurredOn ?? now())->toDateString(),
            'currency_id' => $currency->id,
            'exchange_rate' => $payload['exchange_rate'],
            'payload' => $payload,
        ]);
    }
}
