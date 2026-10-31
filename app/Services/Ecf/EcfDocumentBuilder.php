<?php

namespace App\Services\Ecf;

use App\Enums\AdditionalTaxCalculationType;
use App\Enums\NcfType;
use App\Enums\PaymentTerms;
use App\Exceptions\EcfException;
use App\Models\AdditionalTax;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;
use App\Services\Settings;
use App\Support\Ecf\EcfAdditionalTax;
use App\Support\Ecf\EcfDocumentData;
use App\Support\Ecf\EcfForeignCurrency;
use App\Support\Ecf\EcfItem;
use App\Support\Ecf\EcfParty;
use App\Support\Ecf\EcfReference;
use App\Support\Ecf\EcfTotals;
use App\Support\FiscalId;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Maps a sales document to the provider-independent e-CF data. Runs before any sequence is consumed:
 * every refusal (missing company data, quantity with more than two decimals, unmapped tax, unknown ITBIS rate,
 * totals that differ from the document) throws EcfException.
 */
class EcfDocumentBuilder
{
    private const DOP = 'DOP';

    private const MAX_EXCHANGE_RATE = '999.9999';

    public function __construct(private readonly Settings $settings) {}

    public function fromInvoice(Invoice $invoice, NcfType $type, string $encf, ?CarbonImmutable $expiresOn): EcfDocumentData
    {
        $invoice->loadMissing(['currency', 'salesperson', 'lines.product.unit']);
        $isCredit = $invoice->payment_terms === PaymentTerms::Credit;

        return $this->assemble(
            type: $type,
            encf: $encf,
            expiresOn: $expiresOn,
            issueDate: CarbonImmutable::instance($invoice->issue_date),
            internalNumber: $invoice->number,
            currencyCode: $invoice->currency->code,
            exchangeRate: $invoice->exchange_rate,
            lines: $invoice->lines,
            salespersonCode: $invoice->salesperson->code,
            tipoPago: $this->tipoPago($invoice),
            dueDate: $isCredit ? CarbonImmutable::instance($invoice->due_date) : null,
            terminoPago: $isCredit ? $invoice->credit_days.' días' : null,
            buyer: $this->buyer($type, $invoice->client_tax_id, $invoice->client_name, $invoice->client_address),
            reference: null,
            indicadorNotaCredito: null,
            documentTotal: (string) $invoice->total,
        );
    }

    public function fromCreditNote(CreditNote $note, NcfType $type, string $encf, ?CarbonImmutable $expiresOn): EcfDocumentData
    {
        $note->loadMissing(['currency', 'salesperson', 'invoice', 'lines.product.unit']);
        $invoice = $note->invoice;
        $issueDate = CarbonImmutable::instance($note->issue_date);
        $originalDate = CarbonImmutable::instance($invoice->issue_date);

        return $this->assemble(
            type: $type,
            encf: $encf,
            expiresOn: $expiresOn,
            issueDate: $issueDate,
            internalNumber: $note->number,
            currencyCode: $note->currency->code,
            exchangeRate: $note->exchange_rate,
            lines: $note->lines,
            salespersonCode: $note->salesperson->code,
            tipoPago: $this->tipoPago($invoice),
            dueDate: null,
            terminoPago: null,
            buyer: $this->buyer($invoice->ncf_type, $invoice->client_tax_id, $invoice->client_name, $invoice->client_address),
            reference: new EcfReference(
                ncfModificado: (string) $invoice->ncf,
                fechaNcfModificado: $originalDate,
                codigoModificacion: self::modificationCode($note),
                razonModificacion: mb_substr($note->reason->label(), 0, 90),
            ),
            indicadorNotaCredito: $type === NcfType::E34 ? $this->indicadorNotaCredito($originalDate, $issueDate) : null,
            documentTotal: (string) $note->total,
        );
    }

    public function fromDebitNote(DebitNote $note, NcfType $type, string $encf, ?CarbonImmutable $expiresOn): EcfDocumentData
    {
        $note->loadMissing(['currency', 'salesperson', 'invoice', 'lines.product.unit']);
        $invoice = $note->invoice;

        return $this->assemble(
            type: $type,
            encf: $encf,
            expiresOn: $expiresOn,
            issueDate: CarbonImmutable::instance($note->issue_date),
            internalNumber: $note->number,
            currencyCode: $note->currency->code,
            exchangeRate: $note->exchange_rate,
            lines: $note->lines,
            salespersonCode: $note->salesperson->code,
            tipoPago: $this->tipoPago($invoice),
            dueDate: null,
            terminoPago: null,
            buyer: $this->buyer($invoice->ncf_type, $invoice->client_tax_id, $invoice->client_name, $invoice->client_address),
            reference: new EcfReference(
                ncfModificado: (string) $invoice->ncf,
                fechaNcfModificado: CarbonImmutable::instance($invoice->issue_date),
                codigoModificacion: self::modificationCode($note),
                razonModificacion: mb_substr($note->reason->label(), 0, 90),
            ),
            indicadorNotaCredito: null,
            documentTotal: (string) $note->total,
        );
    }

    /** Rule 8: a credit note for the whole invoice amount is 1 (anula), any other credit note is 3; debit notes are always 3. */
    public static function modificationCode(CreditNote|DebitNote $note): int
    {
        if ($note instanceof DebitNote) {
            return 3;
        }

        $invoice = $note->invoice;

        return Decimal::cmp($note->total, Decimal::sum([$invoice->total, $invoice->debited_amount])) === 0 ? 1 : 3;
    }

    public static function modificationLabel(int $code): string
    {
        return match ($code) {
            1 => 'Anula el NCF modificado',
            2 => 'Corrige texto del comprobante fiscal modificado',
            3 => 'Corrige montos del NCF modificado',
            4 => 'Reemplazo NCF emitido en contingencia',
            default => throw new \InvalidArgumentException("Código de modificación desconocido: {$code}"),
        };
    }

    /**
     * @param  Collection<int, mixed>  $lines  invoice, credit note or debit note lines
     */
    private function assemble(
        NcfType $type,
        string $encf,
        ?CarbonImmutable $expiresOn,
        CarbonImmutable $issueDate,
        ?string $internalNumber,
        string $currencyCode,
        string $exchangeRate,
        Collection $lines,
        string $salespersonCode,
        int $tipoPago,
        ?CarbonImmutable $dueDate,
        ?string $terminoPago,
        EcfParty $buyer,
        ?EcfReference $reference,
        ?int $indicadorNotaCredito,
        string $documentTotal,
    ): EcfDocumentData {
        $issuer = $this->issuer();

        if ($type->expires() && $expiresOn === null) {
            throw new EcfException("La secuencia {$type->value} requiere fecha de vencimiento para el e-CF.");
        }

        $additionalTaxes = $this->additionalTaxesByCode($lines);
        $isForeign = $currencyCode !== self::DOP;
        if ($isForeign && Decimal::cmp($exchangeRate, self::MAX_EXCHANGE_RATE) > 0) {
            throw new EcfException("La tasa de cambio {$exchangeRate} excede el máximo admitido por el e-CF (".self::MAX_EXCHANGE_RATE.').');
        }

        $rows = $this->rows($lines, $isForeign ? $exchangeRate : '1', $additionalTaxes);
        $foreign = $isForeign
            ? new EcfForeignCurrency(
                tipoMoneda: $currencyCode,
                tipoCambio: Decimal::scale($exchangeRate, 4),
                totals: $this->totals($this->rows($lines, '1', $additionalTaxes)),
            )
            : null;
        $totals = $this->totals($rows);

        $documentCurrencyTotal = $foreign !== null ? $foreign->totals->montoTotal : $totals->montoTotal;
        if (Decimal::cmp($documentCurrencyTotal, Decimal::money($documentTotal)) !== 0) {
            throw new EcfException("El total del e-CF ({$documentCurrencyTotal}) no coincide con el total del documento (".Decimal::money($documentTotal).').');
        }

        return new EcfDocumentData(
            type: $type,
            encf: $encf,
            fechaEmision: $issueDate,
            fechaVencimientoSecuencia: $type->expires() ? $expiresOn : null,
            tipoPago: $tipoPago,
            fechaLimitePago: $dueDate,
            terminoPago: $terminoPago,
            emisor: $issuer,
            comprador: $buyer,
            items: array_map(fn (array $row): EcfItem => $row['item'], $rows),
            totals: $totals,
            otraMoneda: $foreign,
            referencia: $reference,
            indicadorNotaCredito: $indicadorNotaCredito,
            numeroFacturaInterna: $this->nullIfBlank($internalNumber, 20),
            codigoVendedor: $this->nullIfBlank($salespersonCode, 60),
        );
    }

    private function issuer(): EcfParty
    {
        $rnc = FiscalId::normalize((string) $this->settings->get('company.rnc', ''));
        if ($rnc === '') {
            throw new EcfException('Configura el RNC de la empresa en Configuración antes de emitir e-CF.');
        }

        $name = (string) $this->settings->get('company.name', '');
        $razonSocial = mb_substr((string) ($this->settings->get('company.legal_name') ?: $name), 0, 150);

        $address = trim((string) $this->settings->get('company.address', ''));
        if ($address === '') {
            throw new EcfException('Configura la dirección de la empresa en Configuración antes de emitir e-CF.');
        }

        return new EcfParty(
            rnc: $rnc,
            razonSocial: $razonSocial,
            nombreComercial: $name !== '' && $name !== $razonSocial ? $name : null,
            direccion: mb_substr($address, 0, 100),
            municipio: $this->nullIfBlank($this->settings->get('ecf.company.municipio')),
            provincia: $this->nullIfBlank($this->settings->get('ecf.company.provincia')),
            correo: $this->nullIfBlank($this->settings->get('company.email')),
        );
    }

    /**
     * An RNC is mandatory for E31/E44/E45 (taxed to a registered buyer); E32 may omit it and falls back to "Consumidor final".
     * The buyer snapshot is copied from the invoice, so notes use the same rule as the invoice's own type.
     */
    private function buyer(?NcfType $invoiceType, ?string $taxId, ?string $name, ?string $address): EcfParty
    {
        $rnc = FiscalId::normalize((string) $taxId);
        $requiresRnc = in_array($invoiceType?->electronicCounterpart(), [NcfType::E31, NcfType::E44, NcfType::E45], true);
        if ($requiresRnc && $rnc === '') {
            throw new EcfException('La factura de crédito fiscal requiere el RNC o cédula del cliente.');
        }

        $businessName = $name !== null && $name !== '' ? $name : ($requiresRnc ? '' : 'Consumidor final');

        return new EcfParty(
            rnc: $rnc,
            razonSocial: mb_substr($businessName, 0, 150),
            direccion: $this->nullIfBlank($address, 100),
        );
    }

    private function tipoPago(Invoice $invoice): int
    {
        return $invoice->payment_terms === PaymentTerms::Cash ? 1 : 2;
    }

    /**
     * @param  Collection<int, mixed>  $lines
     * @return array<string, AdditionalTax>
     */
    private function additionalTaxesByCode(Collection $lines): array
    {
        $codes = $lines->flatMap(fn (mixed $line): array => array_column($line->additional_taxes ?? [], 'code'))->unique()->values()->all();

        return AdditionalTax::query()->whereIn('code', $codes)->get()->keyBy('code')->all();
    }

    /**
     * Converts every line with $rate ('1' for DOP) and resolves its additional taxes to their Tabla I codes.
     *
     * @param  Collection<int, mixed>  $lines
     * @param  array<string, AdditionalTax>  $additionalTaxes
     * @return list<array{item: EcfItem, taxes: list<array{tax: AdditionalTax, amount: string}>}>
     */
    private function rows(Collection $lines, string $rate, array $additionalTaxes): array
    {
        return array_values($lines->map(function (mixed $line) use ($rate, $additionalTaxes): array {
            $quantity = (string) $line->quantity;
            $cantidad = Decimal::scale($quantity, 2);
            if (Decimal::cmp($cantidad, $quantity) !== 0) {
                throw new EcfException("La línea {$line->line_number} tiene una cantidad con más de 2 decimales ({$quantity}); el e-CF solo admite 2 decimales.");
            }

            $taxes = [];
            foreach ($line->additional_taxes ?? [] as $entry) {
                $code = (string) $entry['code'];
                $tax = $additionalTaxes[$code] ?? null;
                if ($tax === null || blank($tax->dgii_code)) {
                    throw new EcfException("El impuesto adicional {$code} no tiene código DGII configurado (Tabla I del Formato e-CF).");
                }
                $taxes[] = ['tax' => $tax, 'amount' => Decimal::mul((string) $entry['amount'], $rate, 2)];
            }

            $description = (string) $line->description;

            return [
                'item' => new EcfItem(
                    numeroLinea: (int) $line->line_number,
                    indicadorFacturacion: $this->indicadorFacturacion((string) $line->itbis_rate, (int) $line->line_number),
                    nombre: mb_substr($description, 0, 80),
                    descripcion: mb_strlen($description) > 80 ? mb_substr($description, 0, 1000) : null,
                    cantidad: $cantidad,
                    unidadMedida: $this->unitCode((string) $line->product->unit->code),
                    precioUnitario: Decimal::mul((string) $line->unit_price, $rate, 4),
                    descuentoMonto: Decimal::mul((string) $line->discount_amount, $rate, 2),
                    impuestosAdicionales: array_map(fn (array $tax): string => (string) $tax['tax']->dgii_code, $taxes),
                    montoItem: Decimal::mul((string) $line->taxable_base, $rate, 2),
                    itbisAmount: Decimal::mul((string) $line->itbis_amount, $rate, 2),
                ),
                'taxes' => $taxes,
            ];
        })->all());
    }

    /**
     * @param  list<array{item: EcfItem, taxes: list<array{tax: AdditionalTax, amount: string}>}>  $rows
     */
    private function totals(array $rows): EcfTotals
    {
        $gravadoI1 = $this->sumOrNull($this->amounts($rows, 1, 'montoItem'));
        $gravadoI2 = $this->sumOrNull($this->amounts($rows, 2, 'montoItem'));
        $exento = $this->sumOrNull($this->amounts($rows, 4, 'montoItem'));
        $itbis1 = $this->sumOrNull($this->amounts($rows, 1, 'itbisAmount'));
        $itbis2 = $this->sumOrNull($this->amounts($rows, 2, 'itbisAmount'));
        $gravadoTotal = $this->sumOrNull(array_filter([$gravadoI1, $gravadoI2]));
        $totalItbis = $this->sumOrNull(array_filter([$itbis1, $itbis2]));

        [$additionalTaxes, $impuestoAdicional] = $this->additionalTaxTotals($rows);

        return new EcfTotals(
            montoGravadoTotal: $gravadoTotal,
            montoGravadoI1: $gravadoI1,
            montoGravadoI2: $gravadoI2,
            montoGravadoI3: null,
            montoExento: $exento,
            totalItbis: $totalItbis,
            totalItbis1: $itbis1,
            totalItbis2: $itbis2,
            totalItbis3: null,
            montoImpuestoAdicional: $impuestoAdicional,
            impuestosAdicionales: $additionalTaxes,
            montoTotal: Decimal::sum([$gravadoTotal ?? '0', $exento ?? '0', $totalItbis ?? '0', $impuestoAdicional ?? '0']),
        );
    }

    /**
     * @param  list<array{item: EcfItem, taxes: list<array{tax: AdditionalTax, amount: string}>}>  $rows
     * @return list<string>
     */
    private function amounts(array $rows, int $indicator, string $field): array
    {
        $values = [];
        foreach ($rows as $row) {
            if ($row['item']->indicadorFacturacion === $indicator) {
                $values[] = $row['item']->{$field};
            }
        }

        return $values;
    }

    /**
     * @param  list<array{item: EcfItem, taxes: list<array{tax: AdditionalTax, amount: string}>}>  $rows
     * @return array{0: list<EcfAdditionalTax>, 1: ?string}
     */
    private function additionalTaxTotals(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            foreach ($row['taxes'] as $entry) {
                $groups[$entry['tax']->dgii_code] ??= ['tax' => $entry['tax'], 'amounts' => []];
                $groups[$entry['tax']->dgii_code]['amounts'][] = $entry['amount'];
            }
        }

        $taxes = [];
        $allAmounts = [];
        foreach ($groups as $group) {
            $tax = $group['tax'];
            $amount = Decimal::sum($group['amounts']);
            $allAmounts[] = $amount;
            $code = (int) $tax->dgii_code;
            $tasa = $tax->calculation_type === AdditionalTaxCalculationType::PercentOfBase
                ? Decimal::scale((string) $tax->rate, 2)
                : Decimal::scale((string) $tax->amount, 2);

            $taxes[] = new EcfAdditionalTax(
                tipoImpuesto: (string) $tax->dgii_code,
                tasa: $tasa,
                especifico: $code >= 6 && $code <= 22 ? $amount : null,
                advalorem: $code >= 23 && $code <= 39 ? $amount : null,
                otros: $code >= 1 && $code <= 5 ? $amount : null,
            );
        }

        $total = Decimal::sum($allAmounts);

        return [$taxes, Decimal::isZero($total) ? null : $total];
    }

    /** @param  array<int|string, string>  $values */
    private function sumOrNull(array $values): ?string
    {
        return $values === [] ? null : Decimal::sum($values);
    }

    private function indicadorFacturacion(string $itbisRate, int $lineNumber): int
    {
        return match (true) {
            Decimal::cmp($itbisRate, '18.00') === 0 => 1,
            Decimal::cmp($itbisRate, '16.00') === 0 => 2,
            Decimal::isZero($itbisRate) => 4,
            default => throw new EcfException("La línea {$lineNumber} tiene una tasa de ITBIS de {$itbisRate} % que el e-CF no admite (solo 18 %, 16 % o exento)."),
        };
    }

    private function unitCode(string $unit): int
    {
        $codes = config('ecf.unit_codes');

        return (int) ($codes[$unit] ?? $codes['default']);
    }

    /** Notes whose referenced e-CF is older than 30 days are flagged for the DGII (IndicadorNotaCredito 1). */
    private function indicadorNotaCredito(CarbonImmutable $originalDate, CarbonImmutable $noteDate): int
    {
        return $originalDate->diffInDays($noteDate) > 30 ? 1 : 0;
    }

    private function nullIfBlank(mixed $value, ?int $length = null): ?string
    {
        $text = trim((string) $value);

        if ($text === '') {
            return null;
        }

        return $length === null ? $text : mb_substr($text, 0, $length);
    }
}
