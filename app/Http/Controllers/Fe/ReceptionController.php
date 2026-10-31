<?php

namespace App\Http\Controllers\Fe;

use App\Http\Controllers\Controller;
use App\Services\Ecf\CertificateStore;
use App\Services\Ecf\ReceivedEcfService;
use App\Services\Settings;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Public DGII receiver endpoints (DGII-direct mode only). Paths are matched case-insensitively in routes/fe.php. */
class ReceptionController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CertificateStore $certificates,
    ) {}

    public function receive(Request $request, ReceivedEcfService $receivedEcfs): Response
    {
        if (! $this->available()) {
            return response('Servicio no disponible', 503);
        }

        $result = $receivedEcfs->receive($this->payload($request), $request->ip());

        return response($result['xml'], 200)->header('Content-Type', 'application/xml');
    }

    public function commercialApproval(Request $request, ReceivedEcfService $receivedEcfs): Response
    {
        if (! $this->available()) {
            return response('Servicio no disponible', 503);
        }

        $approved = $receivedEcfs->applyCommercialApproval($this->payload($request));

        return response($approved ? 'Aprobación comercial recibida' : 'Aprobación comercial no válida', $approved ? 200 : 400)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    private function available(): bool
    {
        $certificate = $this->certificates->current();

        return $this->settings->get('ecf.provider') === 'dgii' && $certificate !== null && ! $certificate->isExpired();
    }

    private function payload(Request $request): string
    {
        return $request->file('xml')?->getContent() ?? (string) $request->input('xml');
    }
}
