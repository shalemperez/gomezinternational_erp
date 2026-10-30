<?php

namespace App\Services\Fiscal;

use App\Enums\NcfType;
use App\Exceptions\FiscalNumberingException;
use App\Models\NcfSequence;
use App\Services\Settings;
use App\Support\Fiscal\FiscalNumber;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class FiscalNumberingService
{
    public function __construct(private readonly Settings $settings) {}

    public function mode(): string
    {
        return (string) $this->settings->get('fiscal.mode', 'ncf');
    }

    /** Traditional NCF assignment (Phase 3 delegates to the EcfProvider when mode() === 'ecf'). */
    public function assign(NcfType $type, CarbonInterface $issueDate): FiscalNumber
    {
        return DB::transaction(function () use ($type, $issueDate): FiscalNumber {
            $candidates = NcfSequence::query()->where('ncf_type', $type->value)->where('is_active', true)->orderBy('range_from')->lockForUpdate()->get();
            if ($candidates->isEmpty()) {
                throw new FiscalNumberingException("No hay una secuencia activa de {$type->label()} ({$type->value}).");
            }
            $usable = $candidates->first(fn (NcfSequence $s) => $s->next_number <= $s->range_to && ! $this->isExpired($s, $type, $issueDate));
            if ($usable === null) {
                $last = $candidates->last();
                if ($this->isExpired($last, $type, $issueDate)) {
                    throw new FiscalNumberingException("La secuencia de {$type->value} venció el {$last->expires_on->format('d/m/Y')}; registra una nueva secuencia autorizada por la DGII.");
                }
                throw new FiscalNumberingException("La secuencia de {$type->value} está agotada; registra una nueva secuencia autorizada por la DGII.");
            }
            $number = $usable->next_number;
            $usable->next_number = $number + 1;
            if ($usable->next_number > $usable->range_to) {
                $usable->exhausted_at = now();
            }
            $usable->save();
            if ($usable->exhausted_at !== null) {
                activity('ncf_sequences')->performedOn($usable)->event('exhausted')->log("Secuencia {$type->value} agotada");
            }

            return new FiscalNumber(
                $type->value.str_pad((string) $number, 8, '0', STR_PAD_LEFT),
                $type,
                $type->expires() && $usable->expires_on ? CarbonImmutable::parse($usable->expires_on) : null,
                $usable->id,
            );
        });
    }

    private function isExpired(NcfSequence $s, NcfType $type, CarbonInterface $issueDate): bool
    {
        return $type->expires() && $s->expires_on !== null && $issueDate->toDateString() > $s->expires_on->toDateString();
    }
}
