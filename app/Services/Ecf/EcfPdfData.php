<?php

namespace App\Services\Ecf;

use App\Enums\EcfStatus;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\Invoice;

/**
 * Electronic-representation fields printed on the sales PDFs of e-CF documents (RI, GC-17 "RI content").
 */
class EcfPdfData
{
    private const CONTINGENCY_LEGEND = 'e-CF emitido en modalidad de Contingencia, el cual podrá ser consultado para su validez fiscal, a partir de las setenta y dos (72) horas.';

    /**
     * @return array{encf: string, type_label: string, expires_on: ?string, modified_ncf: ?string, modification_label: ?string, status: string, status_label: string, is_valid: bool, security_code: ?string, signed_at: ?string, qr_png: ?string, legend: ?string}|null null unless the document was issued in ecf mode
     */
    public static function for(Invoice|CreditNote|DebitNote $document): ?array
    {
        $ecfDocument = $document->fiscal_mode === 'ecf' ? $document->ecfDocument : null;

        if ($ecfDocument === null) {
            return null;
        }

        $note = $document instanceof Invoice ? null : $document;
        $modificationCode = $note === null ? null : EcfDocumentBuilder::modificationCode($note);

        return [
            'encf' => $ecfDocument->encf,
            'type_label' => $ecfDocument->ncf_type->label(),
            'expires_on' => $ecfDocument->ncf_type->expires() ? $document->ncf_expires_on?->format('d/m/Y') : null,
            'modified_ncf' => $note?->modified_ncf,
            'modification_label' => $modificationCode === null ? null : $modificationCode.' - '.EcfDocumentBuilder::modificationLabel($modificationCode),
            'status' => $ecfDocument->status->value,
            'status_label' => $ecfDocument->status->label(),
            'is_valid' => $ecfDocument->status->isValidForTax(),
            'security_code' => $ecfDocument->security_code,
            'signed_at' => $ecfDocument->signed_at?->setTimezone('America/Santo_Domingo')->format('d-m-Y H:i:s'),
            'qr_png' => $ecfDocument->qr_url === null ? null : app(QrCodeGenerator::class)->pngDataUri($ecfDocument->qr_url),
            'legend' => self::legend($ecfDocument->status),
        ];
    }

    private static function legend(EcfStatus $status): ?string
    {
        return match ($status) {
            EcfStatus::Pending, EcfStatus::Signed, EcfStatus::Sent, EcfStatus::InProcess => self::CONTINGENCY_LEGEND,
            EcfStatus::Rejected => 'RECHAZADO POR LA DGII — sin validez fiscal',
            EcfStatus::Cancelled => 'ANULADO',
            EcfStatus::Accepted, EcfStatus::ConditionallyAccepted => null,
        };
    }
}
