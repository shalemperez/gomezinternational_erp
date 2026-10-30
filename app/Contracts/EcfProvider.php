<?php

namespace App\Contracts;

use App\Exceptions\EcfException;
use App\Models\EcfAnnulment;
use App\Models\EcfDocument;
use App\Support\Ecf\EcfDocumentData;
use App\Support\Ecf\EcfSubmissionResult;

/**
 * The only seam between the ERP and the fiscal authority: DGII direct, PSFE (Alanube) or the fake provider.
 */
interface EcfProvider
{
    public function name(): string;

    /** Whether the ERP signs the XML itself (true) or the provider signs it remotely (false). */
    public function signsLocally(): bool;

    public function prepare(EcfDocument $document, EcfDocumentData $data): void;

    /** @throws EcfException when the provider cannot be reached */
    public function submit(EcfDocument $document): EcfSubmissionResult;

    public function poll(EcfDocument $document): EcfSubmissionResult;

    /**
     * What the provider already holds for this e-NCF, asked before a resend after an unclear failure; null when it has
     * no record (the caller sends again).
     *
     * @throws EcfException when the provider cannot be reached
     */
    public function lookup(EcfDocument $document): ?EcfSubmissionResult;

    public function annul(EcfAnnulment $annulment, string $signedXml): EcfSubmissionResult;
}
