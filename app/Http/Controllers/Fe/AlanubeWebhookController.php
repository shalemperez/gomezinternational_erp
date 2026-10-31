<?php

namespace App\Http\Controllers\Fe;

use App\Http\Controllers\Controller;
use App\Jobs\PollEcfStatus;
use App\Models\EcfDocument;
use App\Services\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Public Alanube webhook (PSFE mode only). A valid x-api-key header triggers an immediate poll of the document; the
 * status itself is always read back from Alanube, never taken from the notification.
 */
class AlanubeWebhookController extends Controller
{
    public function __construct(private readonly Settings $settings) {}

    public function handle(Request $request): JsonResponse|Response
    {
        $expectedKey = (string) $this->settings->get('ecf.alanube.webhook_key', '');
        $givenKey = $request->header('x-api-key');
        if ($expectedKey === '' || ! is_string($givenKey) || ! hash_equals($expectedKey, $givenKey)) {
            return response('Clave inválida', 401);
        }

        $trackId = $request->string('id')->toString() ?: $request->string('document.id')->toString();
        $document = $trackId === '' ? null : EcfDocument::query()->where('provider', 'alanube')->where('track_id', $trackId)->first();

        if ($document !== null) {
            activity('ecf_documents')->performedOn($document)->event('webhook_received')
                ->withProperties(['track_id' => $trackId])
                ->log('Notificación de Alanube recibida');

            PollEcfStatus::dispatch($document);
        }

        return response()->json(['ok' => true]);
    }
}
