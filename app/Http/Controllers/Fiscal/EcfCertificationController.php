<?php

namespace App\Http\Controllers\Fiscal;

use App\Enums\EcfEnvironment;
use App\Enums\EcfStatus;
use App\Http\Controllers\Controller;
use App\Jobs\SubmitEcfDocument;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\EcfDocument;
use App\Models\Invoice;
use App\Models\NcfSequence;
use App\Services\Ecf\CertificateStore;
use App\Services\Settings;
use App\Support\Ecf\CertificationChecklist;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class EcfCertificationController extends Controller
{
    #[Middleware('permission:fiscal.ecf.certification')]
    public function index(Settings $settings, CertificateStore $certificateStore): Response
    {
        $environment = (string) $settings->get('ecf.environment', 'test');
        $provider = (string) $settings->get('ecf.provider', 'fake');
        $doneSteps = (array) $settings->get('ecf.certification.steps', []);
        $receiverBaseUrl = (string) $settings->get('ecf.receiver_base_url', config('app.url'));

        return Inertia::render('fiscal/certification/index', [
            'environment' => $environment,
            'environment_label' => EcfEnvironment::tryFrom($environment)?->label() ?? $environment,
            'provider' => $provider,
            'readiness' => $this->readiness($settings, $certificateStore, $environment, $provider, $receiverBaseUrl),
            'steps' => array_map(fn (array $step) => [...$step, 'done' => in_array($step['key'], $doneSteps, true)], CertificationChecklist::steps($provider)),
            'documents' => EcfDocument::query()->where('environment', $environment)->with('document')
                ->latest('created_at')->latest('id')->limit(50)->get()
                ->map(fn (EcfDocument $ecfDocument) => $this->documentRow($ecfDocument))->values()->all(),
            'stats' => [
                'accepted' => $this->countByStatus($environment, [EcfStatus::Accepted, EcfStatus::ConditionallyAccepted]),
                'rejected' => $this->countByStatus($environment, [EcfStatus::Rejected]),
                'pending' => $this->countByStatus($environment, [EcfStatus::Pending, EcfStatus::Signed, EcfStatus::Sent, EcfStatus::InProcess]),
            ],
            'receiverUrls' => [
                'reception' => "{$receiverBaseUrl}/fe/recepcion/api/ecf",
                'approval' => "{$receiverBaseUrl}/fe/aprobacioncomercial/api/ecf",
            ],
        ]);
    }

    #[Middleware('permission:fiscal.ecf.certification')]
    public function steps(Request $request, Settings $settings): RedirectResponse
    {
        $provider = (string) $settings->get('ecf.provider', 'fake');
        $request->validate([
            'key' => ['required', 'string', Rule::in(array_column(CertificationChecklist::steps($provider), 'key'))],
            'done' => ['required', 'boolean'],
        ]);

        $key = $request->string('key')->toString();
        $doneSteps = array_values(array_filter(
            (array) $settings->get('ecf.certification.steps', []),
            fn (string $doneKey) => $doneKey !== $key,
        ));
        if ($request->boolean('done')) {
            $doneSteps[] = $key;
        }

        $settings->set('ecf.certification.steps', $doneSteps);
        activity('settings')->causedBy($request->user())->event('updated')->withProperties(['keys' => ['ecf.certification.steps']])->log('Paso de certificación DGII actualizado');

        return back()->with('success', 'Paso actualizado');
    }

    #[Middleware('permission:fiscal.ecf.certification')]
    public function resubmit(Settings $settings): RedirectResponse
    {
        $documents = EcfDocument::query()
            ->where('environment', (string) $settings->get('ecf.environment', 'test'))
            ->whereIn('status', [EcfStatus::Pending, EcfStatus::Signed])
            ->oldest('created_at')->oldest('id')->limit(20)->get();

        $documents->each(fn (EcfDocument $ecfDocument) => SubmitEcfDocument::dispatch($ecfDocument));

        return back()->with('success', "{$documents->count()} e-CF en cola para reenvío");
    }

    /** @return list<array{key: string, label: string, ok: bool, hint: string}> */
    private function readiness(Settings $settings, CertificateStore $certificateStore, string $environment, string $provider, string $receiverBaseUrl): array
    {
        $certificate = $certificateStore->active();
        $hasCompany = filled($settings->get('company.rnc')) && filled($settings->get('company.address'));
        $hasSequences = collect(['E31', 'E32', 'E33', 'E34'])->every(fn (string $type) => NcfSequence::query()
            ->where('ncf_type', $type)->where('environment', $environment)->where('is_active', true)->exists());
        $isReceiverHttps = str_starts_with($receiverBaseUrl, 'https://');

        return [
            ['key' => 'certificate', 'label' => 'Certificado digital activo y vigente', 'ok' => $certificate !== null && ! $certificate->isExpired(), 'hint' => 'Carga un certificado digital vigente en Facturación electrónica.'],
            ['key' => 'company', 'label' => 'RNC y dirección de la empresa configurados', 'ok' => $hasCompany, 'hint' => 'Completa el RNC y la dirección en la configuración de la empresa.'],
            ['key' => 'sequences', 'label' => 'Secuencias E31, E32, E33 y E34 activas para el ambiente', 'ok' => $hasSequences, 'hint' => 'Registra las secuencias electrónicas de este ambiente en Secuencias NCF.'],
            ['key' => 'provider', 'label' => 'Proveedor distinto de simulado', 'ok' => $provider !== 'fake', 'hint' => 'Cambia el proveedor simulado por DGII directo o Alanube en Facturación electrónica.'],
            ['key' => 'mode', 'label' => 'Modo fiscal e-CF activo', 'ok' => $settings->get('fiscal.mode', 'ncf') === 'ecf', 'hint' => 'Activa el modo e-CF en Facturación electrónica.'],
            ['key' => 'receiver', 'label' => 'URL de recepción pública configurada', 'ok' => $isReceiverHttps, 'hint' => 'Configura una URL pública HTTPS como URL de recepción en Facturación electrónica.'],
        ];
    }

    /** @param  list<EcfStatus>  $statuses */
    private function countByStatus(string $environment, array $statuses): int
    {
        return EcfDocument::query()->where('environment', $environment)->whereIn('status', $statuses)->count();
    }

    /** @return array{id: int, encf: string, type_label: string, status: string, status_label: string, track_id: string|null, created_at: string|null, document_number: string|null, show_url: string, pdf_url: string|null} */
    private function documentRow(EcfDocument $ecfDocument): array
    {
        $document = $this->documentLinks($ecfDocument->document);

        return [
            'id' => $ecfDocument->id,
            'encf' => $ecfDocument->encf,
            'type_label' => $ecfDocument->ncf_type->label(),
            'status' => $ecfDocument->status->value,
            'status_label' => $ecfDocument->status->label(),
            'track_id' => $ecfDocument->track_id,
            'created_at' => $ecfDocument->created_at?->toIso8601String(),
            'document_number' => $document['number'],
            'show_url' => route('fiscal.ecf-documents.show', $ecfDocument),
            'pdf_url' => $document['pdf_url'],
        ];
    }

    /** @return array{number: string|null, pdf_url: string|null} */
    private function documentLinks(?Model $document): array
    {
        return match (true) {
            $document instanceof Invoice => ['number' => $document->number, 'pdf_url' => route('sales.invoices.pdf', $document)],
            $document instanceof CreditNote => ['number' => $document->number, 'pdf_url' => route('sales.credit-notes.pdf', $document)],
            $document instanceof DebitNote => ['number' => $document->number, 'pdf_url' => route('sales.debit-notes.pdf', $document)],
            default => ['number' => null, 'pdf_url' => null],
        };
    }
}
