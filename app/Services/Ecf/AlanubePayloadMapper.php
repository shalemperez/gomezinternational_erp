<?php

namespace App\Services\Ecf;

use App\Enums\NcfType;
use App\Support\Ecf\EcfAdditionalTax;
use App\Support\Ecf\EcfDocumentData;
use App\Support\Ecf\EcfItem;
use App\Support\Ecf\EcfTotals;

/**
 * Maps the provider-independent e-CF data to the English camelCase body of the Alanube API (RES §12). Money and
 * quantities keep the strings of the DTO; absent values are omitted.
 */
class AlanubePayloadMapper
{
    private const INCOME_TYPE = '01';

    private const GOOD_OR_SERVICE_INDICATOR = 1;

    private const ITBIS_S1_RATE = 18;

    private const ITBIS_S2_RATE = 16;

    /** @return array<string, mixed> */
    public function toPayload(EcfDocumentData $data): array
    {
        $payload = [
            'idDoc' => $this->withoutBlanks([
                'encf' => $data->encf,
                'sequenceDueDate' => $data->fechaVencimientoSecuencia?->format('d-m-Y'),
                'incomeType' => self::INCOME_TYPE,
                'paymentType' => $data->tipoPago,
                'taxAmountIndicator' => 0,
                'paymentDeadline' => $data->fechaLimitePago?->format('d-m-Y'),
                'paymentTerm' => $data->terminoPago,
                'creditNoteIndicator' => $data->type === NcfType::E34 ? $data->indicadorNotaCredito : null,
            ]),
            'sender' => $this->withoutBlanks([
                'rnc' => $data->emisor->rnc,
                'companyName' => $data->emisor->razonSocial,
                'tradename' => $data->emisor->nombreComercial,
                'address' => $data->emisor->direccion,
                'municipality' => $data->emisor->municipio,
                'province' => $data->emisor->provincia,
                'mail' => $data->emisor->correo,
                'sellerCode' => $data->codigoVendedor,
                'internalInvoiceNumber' => $data->numeroFacturaInterna,
                'stampDate' => $data->fechaEmision->format('d-m-Y'),
            ]),
            'buyer' => $this->withoutBlanks([
                'rnc' => $data->comprador->rnc,
                'companyName' => $data->comprador->razonSocial,
                'address' => $data->comprador->direccion,
            ]),
            'totals' => $this->totals($data->totals),
            'itemDetails' => array_map($this->item(...), $data->items),
        ];

        if ($data->otraMoneda !== null) {
            $payload['otherCurrency'] = [
                'currencyType' => $data->otraMoneda->tipoMoneda,
                'exchangeRate' => $data->otraMoneda->tipoCambio,
                'totalAmountOtherCurrency' => $data->otraMoneda->totals->montoTotal,
            ];
        }

        if ($data->referencia !== null) {
            $payload['informationReference'] = $this->withoutBlanks([
                'ncfModified' => $data->referencia->ncfModificado,
                'ncfModifiedDate' => $data->referencia->fechaNcfModificado->format('d-m-Y'),
                'modificationCode' => $data->referencia->codigoModificacion,
                'modificationReason' => $data->referencia->razonModificacion,
            ]);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function totals(EcfTotals $totals): array
    {
        return $this->withoutBlanks([
            'totalTaxedAmount' => $totals->montoGravadoTotal,
            'i1AmountTaxed' => $totals->montoGravadoI1,
            'i2AmountTaxed' => $totals->montoGravadoI2,
            'i3AmountTaxed' => $totals->montoGravadoI3,
            'exemptAmount' => $totals->montoExento,
            'itbisS1' => $totals->montoGravadoI1 !== null ? self::ITBIS_S1_RATE : null,
            'itbisS2' => $totals->montoGravadoI2 !== null ? self::ITBIS_S2_RATE : null,
            'itbisTotal' => $totals->totalItbis,
            'itbis1Total' => $totals->totalItbis1,
            'itbis2Total' => $totals->totalItbis2,
            'itbis3Total' => $totals->totalItbis3,
            'additionalTaxAmount' => $totals->montoImpuestoAdicional,
            'additionalTaxes' => $totals->impuestosAdicionales === [] ? null : array_map($this->additionalTax(...), $totals->impuestosAdicionales),
            'totalAmount' => $totals->montoTotal,
        ]);
    }

    /** @return array<string, mixed> */
    private function additionalTax(EcfAdditionalTax $tax): array
    {
        return $this->withoutBlanks([
            'tipoImpuesto' => $tax->tipoImpuesto,
            'tasa' => $tax->tasa,
            'especifico' => $tax->especifico,
            'advalorem' => $tax->advalorem,
            'otros' => $tax->otros,
        ]);
    }

    /** @return array<string, mixed> */
    private function item(EcfItem $item): array
    {
        return $this->withoutBlanks([
            'lineNumber' => $item->numeroLinea,
            'billingIndicator' => $item->indicadorFacturacion,
            'itemName' => $item->nombre,
            'itemDescription' => $item->descripcion,
            'goodServiceIndicator' => self::GOOD_OR_SERVICE_INDICATOR,
            'quantityItem' => $item->cantidad,
            'unitMeasure' => $item->unidadMedida,
            'unitPriceItem' => $item->precioUnitario,
            'discountAmount' => $item->descuentoMonto,
            'additionalTaxes' => $item->impuestosAdicionales === [] ? null : $item->impuestosAdicionales,
            'itemAmount' => $item->montoItem,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function withoutBlanks(array $values): array
    {
        return array_filter($values, fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
