<?php

namespace App\Http\Controllers\Fiscal;

use App\Enums\NcfType;
use App\Exceptions\EcfException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\StoreEcfAnnulmentRangeRequest;
use App\Models\EcfAnnulment;
use App\Models\EcfAnnulmentRange;
use App\Models\User;
use App\Services\Ecf\EcfSequenceAnnulmentService;
use App\Services\Ecf\EcfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EcfAnnulmentController extends Controller
{
    #[Middleware('permission:fiscal.ecf.annul')]
    public function index(EcfSequenceAnnulmentService $annulments, EcfService $ecf): Response
    {
        return Inertia::render('fiscal/ecf-annulments/index', [
            'pending' => $annulments->pendingRanges()->map(fn (EcfAnnulmentRange $range) => [
                'id' => $range->id,
                'ncf_type' => $range->ncf_type,
                'range_from' => (int) $range->range_from,
                'range_to' => (int) $range->range_to,
                'encf_from' => $annulments->encf(NcfType::from($range->ncf_type), (int) $range->range_from),
                'encf_to' => $annulments->encf(NcfType::from($range->ncf_type), (int) $range->range_to),
                'quantity' => (int) $range->quantity,
                'reason' => $range->reason,
                'document_number' => $range->ecfDocument?->document?->getAttribute('number'),
            ])->values()->all(),
            'history' => EcfAnnulment::query()->where('environment', $ecf->environment()->value)->latest('id')->get()
                ->map(fn (EcfAnnulment $annulment) => [
                    'id' => $annulment->id,
                    'status' => $annulment->status,
                    'status_label' => $this->statusLabel($annulment->status),
                    'sent_at' => $annulment->sent_at?->toIso8601String(),
                    'ranges_count' => $annulment->ranges_count,
                    'quantity' => $annulment->quantity,
                    'messages' => array_values((array) ($annulment->response['mensajes'] ?? [])),
                ])->values()->all(),
            'environment_label' => $ecf->environment()->label(),
        ]);
    }

    #[Middleware('permission:fiscal.ecf.annul')]
    public function storeRange(StoreEcfAnnulmentRangeRequest $request, EcfSequenceAnnulmentService $annulments): RedirectResponse
    {
        $annulments->addRange(
            NcfType::from($request->string('ncf_type')->toString()),
            $request->integer('range_from'),
            $request->integer('range_to'),
            $request->string('reason')->toString(),
            $this->actor($request),
        );

        return redirect()->route('fiscal.ecf-annulments.index')->with('success', 'Rango agregado a la anulación pendiente');
    }

    #[Middleware('permission:fiscal.ecf.annul')]
    public function destroyRange(Request $request, EcfSequenceAnnulmentService $annulments, EcfAnnulmentRange $range): RedirectResponse
    {
        try {
            $annulments->deleteRange($range, $this->actor($request));
        } catch (EcfException $e) {
            return redirect()->route('fiscal.ecf-annulments.index')->with('error', $e->getMessage());
        }

        return redirect()->route('fiscal.ecf-annulments.index')->with('success', 'Rango eliminado');
    }

    #[Middleware('permission:fiscal.ecf.annul')]
    public function send(Request $request, EcfSequenceAnnulmentService $annulments): RedirectResponse
    {
        $validated = $request->validate([
            'range_ids' => ['required', 'array', 'min:1'],
            'range_ids.*' => ['integer', Rule::exists('ecf_annulment_ranges', 'id')->whereNull('ecf_annulment_id')],
        ]);

        $annulment = $annulments->send(array_values(array_map(fn (mixed $id): int => (int) $id, $validated['range_ids'])), $this->actor($request));

        return redirect()->route('fiscal.ecf-annulments.index')->with('success', 'Anulación enviada: '.$this->statusLabel($annulment->status));
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'sent' => 'Enviada',
            'accepted' => 'Aceptada',
            'rejected' => 'Rechazada',
            'sending' => 'Enviando',
            'failed' => 'Falló el envío',
            default => 'Pendiente',
        };
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
