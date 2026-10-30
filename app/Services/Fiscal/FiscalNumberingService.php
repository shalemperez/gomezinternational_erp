<?php

namespace App\Services\Fiscal;

use App\Enums\NcfType;
use App\Exceptions\FiscalNumberingException;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Models\NcfSequence;
use App\Services\Settings;
use App\Support\Fiscal\FiscalNumber;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class FiscalNumberingService
{
    public function __construct(private readonly Settings $settings) {}

    /** 'ecf' only when the switch is on and the cutover date (if any) has arrived; otherwise 'ncf'. */
    public function mode(): string
    {
        $cutover = $this->settings->get('fiscal.ecf_cutover_date');
        $switched = (string) $this->settings->get('fiscal.mode', 'ncf') === 'ecf';

        return $switched && (blank($cutover) || today()->toDateString() >= (string) $cutover) ? 'ecf' : 'ncf';
    }

    public function environment(): string
    {
        return (string) $this->settings->get('ecf.environment', 'test');
    }

    /**
     * Assigns the next fiscal number. In mode 'ecf' a traditional type becomes its electronic counterpart and draws from
     * the sequences of the current environment; the traditional sequences always belong to 'prod'.
     */
    public function assign(NcfType $type, CarbonInterface $issueDate): FiscalNumber
    {
        if ($this->mode() === 'ecf') {
            $type = $type->electronicCounterpart();
        } elseif ($type->isElectronic()) {
            throw new FiscalNumberingException('El modo fiscal actual (NCF tradicional) no permite emitir e-CF.');
        }
        $environment = $type->isElectronic() ? $this->environment() : 'prod';

        return DB::transaction(function () use ($type, $issueDate, $environment): FiscalNumber {
            $candidates = NcfSequence::query()->where('ncf_type', $type->value)->where('environment', $environment)->where('is_active', true)->orderBy('range_from')->lockForUpdate()->get();
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
            $ncf = $type->value.str_pad((string) $number, $type->sequenceDigits(), '0', STR_PAD_LEFT);
            if ($this->ncfExists($ncf)) {
                throw new FiscalNumberingException("El NCF {$ncf} ya existe (factura histórica o importada); ajusta el inicio de la secuencia {$type->value} para continuar después del último NCF usado.");
            }
            $usable->next_number = $number + 1;
            if ($usable->next_number > $usable->range_to) {
                $usable->exhausted_at = now();
            }
            $usable->save();
            if ($usable->exhausted_at !== null) {
                activity('ncf_sequences')->performedOn($usable)->event('exhausted')->log("Secuencia {$type->value} agotada");
            }

            return new FiscalNumber(
                $ncf,
                $type,
                $type->expires() && $usable->expires_on ? CarbonImmutable::parse($usable->expires_on) : null,
                $usable->id,
            );
        });
    }

    private function ncfExists(string $ncf): bool
    {
        return Invoice::query()->where('ncf', $ncf)->exists() || CreditNote::query()->where('ncf', $ncf)->exists() || DebitNote::query()->where('ncf', $ncf)->exists();
    }

    private function isExpired(NcfSequence $s, NcfType $type, CarbonInterface $issueDate): bool
    {
        return $type->expires() && $s->expires_on !== null && $issueDate->toDateString() > $s->expires_on->toDateString();
    }
}
