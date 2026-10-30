<?php

namespace App\Services\Ecf;

use App\Enums\NcfType;
use App\Models\EcfAnnulmentRange;
use App\Support\Ecf\EcfAdditionalTax;
use App\Support\Ecf\EcfDocumentData;
use App\Support\Ecf\EcfItem;
use App\Support\Ecf\EcfParty;
use App\Support\Ecf\EcfReference;
use App\Support\Ecf\EcfTotals;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Support\Collection;

class EcfXmlWriter
{
    private const VERSION = '1.0';

    private const TIPO_INGRESOS = '01';

    private const TIMEZONE = 'America/Santo_Domingo';

    /** @var list<NcfType> */
    private const WITH_INDICADOR_MONTO_GRAVADO = [NcfType::E31, NcfType::E32, NcfType::E33, NcfType::E34, NcfType::E44, NcfType::E45];

    /** Writes the unsigned e-CF in the element order of the official XSD; empty values and containers are omitted. */
    public function write(EcfDocumentData $data, CarbonImmutable $fechaHoraFirma): DOMDocument
    {
        $document = $this->newDocument();
        $root = $this->element($document, $document, 'ECF');

        $header = $this->element($document, $root, 'Encabezado');
        $this->text($document, $header, 'Version', self::VERSION);
        $this->idDoc($document, $header, $data);
        $this->emisor($document, $header, $data->emisor, $data);
        $this->comprador($document, $header, $data->comprador);
        $this->totals($document, $header, $data->totals, false);
        $this->otraMoneda($document, $header, $data);

        $this->items($document, $root, $data->items);
        $this->referencia($document, $root, $data->referencia);
        $this->text($document, $root, 'FechaHoraFirma', $fechaHoraFirma->setTimezone(self::TIMEZONE)->format('d-m-Y H:i:s'));

        return $document;
    }

    /** Writes the RFCE summary (consumo e-CF below the RFCE threshold) carrying the security code of the signed e-CF 32. */
    public function writeRfce(EcfDocumentData $data, string $securityCode): DOMDocument
    {
        $document = $this->newDocument();
        $root = $this->element($document, $document, 'RFCE');

        $header = $this->element($document, $root, 'Encabezado');
        $this->text($document, $header, 'Version', self::VERSION);

        $idDoc = $this->element($document, $header, 'IdDoc');
        $this->text($document, $idDoc, 'TipoeCF', $data->type->ecfCode());
        $this->text($document, $idDoc, 'eNCF', $data->encf);
        $this->text($document, $idDoc, 'TipoIngresos', self::TIPO_INGRESOS);
        $this->text($document, $idDoc, 'TipoPago', (string) $data->tipoPago);

        $emisor = $this->element($document, $header, 'Emisor');
        $this->text($document, $emisor, 'RNCEmisor', $data->emisor->rnc);
        $this->text($document, $emisor, 'RazonSocialEmisor', $data->emisor->razonSocial);
        $this->text($document, $emisor, 'FechaEmision', $data->fechaEmision->format('d-m-Y'));

        $comprador = $this->element($document, $header, 'Comprador');
        $this->text($document, $comprador, 'RNCComprador', $data->comprador->rnc);
        $this->text($document, $comprador, 'RazonSocialComprador', $data->comprador->razonSocial);

        $this->totals($document, $header, $data->totals, true);
        $this->text($document, $header, 'CodigoSeguridadeCF', $securityCode);

        return $document;
    }

    /**
     * Writes the ANECF that annuls unused e-NCF ranges: one Anulacion per e-CF type in ascending order, numbered from 1.
     *
     * @param  Collection<int, EcfAnnulmentRange>  $ranges
     */
    public function writeAnecf(string $rncEmisor, Collection $ranges, CarbonImmutable $fechaHora): DOMDocument
    {
        $document = $this->newDocument();
        $root = $this->element($document, $document, 'ANECF');

        $header = $this->element($document, $root, 'Encabezado');
        $this->text($document, $header, 'Version', self::VERSION);
        $this->text($document, $header, 'RncEmisor', $rncEmisor);
        $this->text($document, $header, 'CantidadeNCFAnulados', (string) $ranges->sum('quantity'));
        $this->text($document, $header, 'FechaHoraAnulacioneNCF', $fechaHora->setTimezone(self::TIMEZONE)->format('d-m-Y H:i:s'));

        $detail = $this->element($document, $root, 'DetalleAnulacion');
        $line = 0;
        foreach ($ranges->groupBy('ncf_type')->sortKeys() as $ncfType => $typeRanges) {
            $type = NcfType::from((string) $ncfType);
            $anulacion = $this->element($document, $detail, 'Anulacion');
            $this->text($document, $anulacion, 'NoLinea', (string) ++$line);
            $this->text($document, $anulacion, 'TipoeCF', $type->ecfCode());

            $table = $this->element($document, $anulacion, 'TablaRangoSecuenciasAnuladaseNCF');
            foreach ($typeRanges->sortBy('range_from') as $range) {
                $sequences = $this->element($document, $table, 'Secuencias');
                $this->text($document, $sequences, 'SecuenciaeNCFDesde', $this->encf($type, (int) $range->range_from));
                $this->text($document, $sequences, 'SecuenciaeNCFHasta', $this->encf($type, (int) $range->range_to));
            }

            $this->text($document, $anulacion, 'CantidadeNCFAnulados', (string) $typeRanges->sum('quantity'));
        }

        return $document;
    }

    /** Writes the signed-later acuse de recibo (ARECF) that the buyer returns for a received e-CF. */
    public function writeArecf(string $rncEmisor, string $rncComprador, string $encf, int $estado, ?int $motivo, CarbonImmutable $fechaHora): DOMDocument
    {
        $document = $this->newDocument();
        $root = $this->element($document, $document, 'ARECF');

        $detail = $this->element($document, $root, 'DetalleAcusedeRecibo');
        $this->text($document, $detail, 'Version', self::VERSION);
        $this->text($document, $detail, 'RNCEmisor', $rncEmisor);
        $this->text($document, $detail, 'RNCComprador', $rncComprador);
        $this->text($document, $detail, 'eNCF', $encf);
        $this->text($document, $detail, 'Estado', (string) $estado);
        $this->text($document, $detail, 'CodigoMotivoNoRecibido', $motivo === null ? null : (string) $motivo);
        $this->text($document, $detail, 'FechaHoraAcuseRecibo', $fechaHora->setTimezone(self::TIMEZONE)->format('d-m-Y H:i:s'));

        return $document;
    }

    /** Writes the commercial approval (ACECF) that a buyer sends about one of our e-CF; the motivo is the rejection detail. */
    public function writeAcecf(string $rncEmisor, string $encf, CarbonImmutable $fechaEmision, string $montoTotal, string $rncComprador, int $estado, ?string $motivo, CarbonImmutable $fechaHora): DOMDocument
    {
        $document = $this->newDocument();
        $root = $this->element($document, $document, 'ACECF');

        $detail = $this->element($document, $root, 'DetalleAprobacionComercial');
        $this->text($document, $detail, 'Version', self::VERSION);
        $this->text($document, $detail, 'RNCEmisor', $rncEmisor);
        $this->text($document, $detail, 'eNCF', $encf);
        $this->text($document, $detail, 'FechaEmision', $fechaEmision->format('d-m-Y'));
        $this->text($document, $detail, 'MontoTotal', $montoTotal);
        $this->text($document, $detail, 'RNCComprador', $rncComprador);
        $this->text($document, $detail, 'Estado', (string) $estado);
        $this->text($document, $detail, 'DetalleMotivoRechazo', $motivo);
        $this->text($document, $detail, 'FechaHoraAprobacionComercial', $fechaHora->setTimezone(self::TIMEZONE)->format('d-m-Y H:i:s'));

        return $document;
    }

    private function encf(NcfType $type, int $number): string
    {
        return $type->value.str_pad((string) $number, $type->sequenceDigits(), '0', STR_PAD_LEFT);
    }

    private function newDocument(): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;

        return $document;
    }

    private function element(DOMDocument $document, DOMNode $parent, string $name): DOMElement
    {
        $element = $document->createElement($name);
        $parent->appendChild($element);

        return $element;
    }

    /** Appends a text element; null and empty values are skipped so that no empty tag is ever written. */
    private function text(DOMDocument $document, DOMNode $parent, string $name, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->element($document, $parent, $name)->appendChild($document->createTextNode($value));
    }

    private function idDoc(DOMDocument $document, DOMElement $header, EcfDocumentData $data): void
    {
        $idDoc = $this->element($document, $header, 'IdDoc');
        $this->text($document, $idDoc, 'TipoeCF', $data->type->ecfCode());
        $this->text($document, $idDoc, 'eNCF', $data->encf);

        if ($data->type === NcfType::E34 && $data->indicadorNotaCredito !== null) {
            $this->text($document, $idDoc, 'IndicadorNotaCredito', (string) $data->indicadorNotaCredito);
        }
        if ($data->fechaVencimientoSecuencia !== null && $data->type->expires()) {
            $this->text($document, $idDoc, 'FechaVencimientoSecuencia', $data->fechaVencimientoSecuencia->format('d-m-Y'));
        }
        if (in_array($data->type, self::WITH_INDICADOR_MONTO_GRAVADO, true)) {
            $this->text($document, $idDoc, 'IndicadorMontoGravado', '0');
        }

        $this->text($document, $idDoc, 'TipoIngresos', self::TIPO_INGRESOS);
        $this->text($document, $idDoc, 'TipoPago', (string) $data->tipoPago);
        $this->text($document, $idDoc, 'FechaLimitePago', $data->fechaLimitePago?->format('d-m-Y'));

        if (! in_array($data->type, [NcfType::E33, NcfType::E34], true)) {
            $this->text($document, $idDoc, 'TerminoPago', $data->terminoPago);
        }
    }

    private function emisor(DOMDocument $document, DOMElement $header, EcfParty $party, EcfDocumentData $data): void
    {
        $emisor = $this->element($document, $header, 'Emisor');
        $this->text($document, $emisor, 'RNCEmisor', $party->rnc);
        $this->text($document, $emisor, 'RazonSocialEmisor', $party->razonSocial);
        $this->text($document, $emisor, 'NombreComercial', $party->nombreComercial);
        $this->text($document, $emisor, 'DireccionEmisor', $party->direccion);
        $this->text($document, $emisor, 'Municipio', $party->municipio);
        $this->text($document, $emisor, 'Provincia', $party->provincia);
        $this->text($document, $emisor, 'CorreoEmisor', $party->correo);
        $this->text($document, $emisor, 'CodigoVendedor', $data->codigoVendedor);
        $this->text($document, $emisor, 'NumeroFacturaInterna', $data->numeroFacturaInterna);
        $this->text($document, $emisor, 'FechaEmision', $data->fechaEmision->format('d-m-Y'));
    }

    private function comprador(DOMDocument $document, DOMElement $header, EcfParty $party): void
    {
        $comprador = $this->element($document, $header, 'Comprador');
        $this->text($document, $comprador, 'RNCComprador', $party->rnc);
        $this->text($document, $comprador, 'RazonSocialComprador', $party->razonSocial);
        $this->text($document, $comprador, 'CorreoComprador', $party->correo);
        $this->text($document, $comprador, 'DireccionComprador', $party->direccion);
        $this->text($document, $comprador, 'MunicipioComprador', $party->municipio);
        $this->text($document, $comprador, 'ProvinciaComprador', $party->provincia);
    }

    /** The RFCE carries no ITBIS1/ITBIS2 tags, so $rfce omits them. */
    private function totals(DOMDocument $document, DOMElement $parent, EcfTotals $totals, bool $rfce): void
    {
        $element = $this->element($document, $parent, 'Totales');
        $this->text($document, $element, 'MontoGravadoTotal', $totals->montoGravadoTotal);
        $this->text($document, $element, 'MontoGravadoI1', $totals->montoGravadoI1);
        $this->text($document, $element, 'MontoGravadoI2', $totals->montoGravadoI2);
        $this->text($document, $element, 'MontoGravadoI3', $totals->montoGravadoI3);
        $this->text($document, $element, 'MontoExento', $totals->montoExento);
        if (! $rfce) {
            $this->text($document, $element, 'ITBIS1', $totals->montoGravadoI1 === null ? null : '18');
            $this->text($document, $element, 'ITBIS2', $totals->montoGravadoI2 === null ? null : '16');
        }
        $this->text($document, $element, 'TotalITBIS', $totals->totalItbis);
        $this->text($document, $element, 'TotalITBIS1', $totals->totalItbis1);
        $this->text($document, $element, 'TotalITBIS2', $totals->totalItbis2);
        $this->text($document, $element, 'TotalITBIS3', $totals->totalItbis3);
        $this->text($document, $element, 'MontoImpuestoAdicional', $totals->montoImpuestoAdicional);
        $this->additionalTaxes($document, $element, $totals->impuestosAdicionales);
        $this->text($document, $element, 'MontoTotal', $totals->montoTotal);
    }

    /** @param  list<EcfAdditionalTax>  $taxes */
    private function additionalTaxes(DOMDocument $document, DOMElement $parent, array $taxes): void
    {
        if ($taxes === []) {
            return;
        }

        $container = $this->element($document, $parent, 'ImpuestosAdicionales');
        foreach ($taxes as $tax) {
            $element = $this->element($document, $container, 'ImpuestoAdicional');
            $this->text($document, $element, 'TipoImpuesto', $tax->tipoImpuesto);
            $this->text($document, $element, 'TasaImpuestoAdicional', $tax->tasa);
            $this->text($document, $element, 'MontoImpuestoSelectivoConsumoEspecifico', $tax->especifico);
            $this->text($document, $element, 'MontoImpuestoSelectivoConsumoAdvalorem', $tax->advalorem);
            $this->text($document, $element, 'OtrosImpuestosAdicionales', $tax->otros);
        }
    }

    private function otraMoneda(DOMDocument $document, DOMElement $header, EcfDocumentData $data): void
    {
        if ($data->otraMoneda === null) {
            return;
        }

        $foreign = $data->otraMoneda;
        $totals = $foreign->totals;
        $element = $this->element($document, $header, 'OtraMoneda');
        $this->text($document, $element, 'TipoMoneda', $foreign->tipoMoneda);
        $this->text($document, $element, 'TipoCambio', $foreign->tipoCambio);
        $this->text($document, $element, 'MontoGravadoTotalOtraMoneda', $totals->montoGravadoTotal);
        $this->text($document, $element, 'MontoGravado1OtraMoneda', $totals->montoGravadoI1);
        $this->text($document, $element, 'MontoGravado2OtraMoneda', $totals->montoGravadoI2);
        $this->text($document, $element, 'MontoGravado3OtraMoneda', $totals->montoGravadoI3);
        $this->text($document, $element, 'MontoExentoOtraMoneda', $totals->montoExento);
        $this->text($document, $element, 'TotalITBISOtraMoneda', $totals->totalItbis);
        $this->text($document, $element, 'TotalITBIS1OtraMoneda', $totals->totalItbis1);
        $this->text($document, $element, 'TotalITBIS2OtraMoneda', $totals->totalItbis2);
        $this->text($document, $element, 'TotalITBIS3OtraMoneda', $totals->totalItbis3);
        $this->text($document, $element, 'MontoImpuestoAdicionalOtraMoneda', $totals->montoImpuestoAdicional);

        if ($totals->impuestosAdicionales !== []) {
            $container = $this->element($document, $element, 'ImpuestosAdicionalesOtraMoneda');
            foreach ($totals->impuestosAdicionales as $tax) {
                $taxElement = $this->element($document, $container, 'ImpuestoAdicionalOtraMoneda');
                $this->text($document, $taxElement, 'TipoImpuestoOtraMoneda', $tax->tipoImpuesto);
                $this->text($document, $taxElement, 'TasaImpuestoAdicionalOtraMoneda', $tax->tasa);
                $this->text($document, $taxElement, 'MontoImpuestoSelectivoConsumoEspecificoOtraMoneda', $tax->especifico);
                $this->text($document, $taxElement, 'MontoImpuestoSelectivoConsumoAdvaloremOtraMoneda', $tax->advalorem);
                $this->text($document, $taxElement, 'OtrosImpuestosAdicionalesOtraMoneda', $tax->otros);
            }
        }

        $this->text($document, $element, 'MontoTotalOtraMoneda', $totals->montoTotal);
    }

    /** @param  list<EcfItem>  $items */
    private function items(DOMDocument $document, DOMElement $root, array $items): void
    {
        $details = $this->element($document, $root, 'DetallesItems');
        foreach ($items as $item) {
            $element = $this->element($document, $details, 'Item');
            $this->text($document, $element, 'NumeroLinea', (string) $item->numeroLinea);
            $this->text($document, $element, 'IndicadorFacturacion', (string) $item->indicadorFacturacion);
            $this->text($document, $element, 'NombreItem', $item->nombre);
            $this->text($document, $element, 'IndicadorBienoServicio', '1');
            $this->text($document, $element, 'DescripcionItem', $item->descripcion);
            $this->text($document, $element, 'CantidadItem', $item->cantidad);
            $this->text($document, $element, 'UnidadMedida', (string) $item->unidadMedida);
            $this->text($document, $element, 'PrecioUnitarioItem', $item->precioUnitario);
            $this->text($document, $element, 'DescuentoMonto', $item->descuentoMonto);

            if ($item->impuestosAdicionales !== []) {
                $table = $this->element($document, $element, 'TablaImpuestoAdicional');
                foreach ($item->impuestosAdicionales as $code) {
                    $tax = $this->element($document, $table, 'ImpuestoAdicional');
                    $this->text($document, $tax, 'TipoImpuesto', $code);
                }
            }

            $this->text($document, $element, 'MontoItem', $item->montoItem);
        }
    }

    private function referencia(DOMDocument $document, DOMElement $root, ?EcfReference $reference): void
    {
        if ($reference === null) {
            return;
        }

        $element = $this->element($document, $root, 'InformacionReferencia');
        $this->text($document, $element, 'NCFModificado', $reference->ncfModificado);
        $this->text($document, $element, 'FechaNCFModificado', $reference->fechaNcfModificado->format('d-m-Y'));
        $this->text($document, $element, 'CodigoModificacion', (string) $reference->codigoModificacion);
        $this->text($document, $element, 'RazonModificacion', $reference->razonModificacion);
    }
}
