<?php

namespace App\Services\Fiscal;

use App\Enums\NcfType;
use App\Models\NcfSequence;

class SequenceAlerts
{
    public const LOW_PERCENT = 10;

    public const EXPIRING_DAYS = 30;

    public function __construct(private readonly FiscalNumberingService $numbering) {}

    /** @return list<array{ncf_type: string, label: string, remaining: int, expires_on: ?string, reason: string}> */
    public function current(): array
    {
        $ecfMode = $this->numbering->mode() === 'ecf';
        $types = $ecfMode
            ? [NcfType::E31, NcfType::E32, NcfType::E33, NcfType::E34, NcfType::E44, NcfType::E45]
            : [NcfType::B01, NcfType::B02, NcfType::B03, NcfType::B04, NcfType::B14, NcfType::B15];

        $alerts = [];
        foreach ($types as $type) {
            $environment = $type->isElectronic() ? $this->numbering->environment() : 'prod';
            $sequences = NcfSequence::query()->where('ncf_type', $type->value)->where('environment', $environment)->where('is_active', true)->orderBy('range_from')->get();
            $usable = $sequences->filter(fn (NcfSequence $s) => $s->remaining > 0 && ! ($type->expires() && $s->expires_on && $s->expires_on->lt(today())));
            if ($sequences->isEmpty()) {
                $alerts[] = ['ncf_type' => $type->value, 'label' => $type->label(), 'remaining' => 0, 'expires_on' => null, 'reason' => 'missing'];

                continue;
            }
            if ($usable->isEmpty()) {
                $last = $sequences->last();
                $expired = $type->expires() && $last->expires_on && $last->expires_on->lt(today());
                $alerts[] = ['ncf_type' => $type->value, 'label' => $type->label(), 'remaining' => 0, 'expires_on' => $last->expires_on?->toDateString(), 'reason' => $expired ? 'expired' : 'low'];

                continue;
            }
            $remaining = (int) $usable->sum('remaining');
            $total = (int) $usable->sum(fn (NcfSequence $s) => $s->range_to - $s->range_from + 1);
            $soonest = $usable->filter(fn (NcfSequence $s) => $s->expires_on !== null)->min('expires_on');
            if ($type->expires() && $soonest !== null && today()->diffInDays($soonest, false) <= self::EXPIRING_DAYS) {
                $alerts[] = ['ncf_type' => $type->value, 'label' => $type->label(), 'remaining' => $remaining, 'expires_on' => $soonest->toDateString(), 'reason' => 'expiring'];
            } elseif ($total > 0 && ($remaining * 100 / $total) < self::LOW_PERCENT) {
                $alerts[] = ['ncf_type' => $type->value, 'label' => $type->label(), 'remaining' => $remaining, 'expires_on' => $soonest?->toDateString(), 'reason' => 'low'];
            }
        }

        return $alerts;
    }
}
