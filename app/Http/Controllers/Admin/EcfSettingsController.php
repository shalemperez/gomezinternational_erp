<?php

namespace App\Http\Controllers\Admin;

use App\Enums\EcfEnvironment;
use App\Exceptions\EcfCertificateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreEcfCertificateRequest;
use App\Http\Requests\Admin\UpdateEcfSettingsRequest;
use App\Models\EcfCertificate;
use App\Models\EcfDocument;
use App\Models\User;
use App\Services\Ecf\CertificateStore;
use App\Services\Fiscal\FiscalNumberingService;
use App\Services\Settings;
use App\Support\FiscalId;
use App\Support\Money\Decimal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class EcfSettingsController extends Controller
{
    #[Middleware('permission:fiscal.ecf.settings')]
    public function index(Settings $settings): Response
    {
        $certificate = EcfCertificate::query()->orderByDesc('is_active')->latest('id')->first();
        $companyRnc = (string) $settings->get('company.rnc', '');
        $receiverBaseUrl = (string) $settings->get('ecf.receiver_base_url', config('app.url'));

        return Inertia::render('admin/ecf-settings/index', [
            'values' => $this->values($settings),
            'hasAlanubeKey' => filled($settings->get('ecf.alanube.api_key')),
            'certificate' => $certificate === null ? null : $this->certificateSummary($certificate),
            'companyRnc' => $companyRnc !== '' ? $companyRnc : null,
            'warnings' => $this->warnings($certificate, $companyRnc),
            'documentCounts' => collect(EcfEnvironment::cases())->mapWithKeys(fn (EcfEnvironment $environment) => [$environment->value => EcfDocument::query()->where('environment', $environment)->count()])->all(),
            'receiverUrls' => [
                'reception' => "{$receiverBaseUrl}/fe/recepcion/api/ecf",
                'approval' => "{$receiverBaseUrl}/fe/aprobacioncomercial/api/ecf",
            ],
            'alanubeWebhook' => [
                'url' => "{$receiverBaseUrl}/fe/alanube/webhook",
                'key' => $settings->get('ecf.alanube.webhook_key'),
            ],
            'fiscalModeEffective' => app(FiscalNumberingService::class)->mode(),
        ]);
    }

    #[Middleware('permission:fiscal.ecf.settings')]
    public function update(UpdateEcfSettingsRequest $request, Settings $settings): RedirectResponse
    {
        $validated = $request->validated();
        $flat = [
            'fiscal.mode' => data_get($validated, 'fiscal.mode'),
            'fiscal.ecf_cutover_date' => data_get($validated, 'fiscal.ecf_cutover_date'),
            'ecf.provider' => data_get($validated, 'ecf.provider'),
            'ecf.environment' => data_get($validated, 'ecf.environment'),
            'ecf.rfce_threshold' => Decimal::money(data_get($validated, 'ecf.rfce_threshold')),
            'ecf.company.municipio' => data_get($validated, 'ecf.company.municipio'),
            'ecf.company.provincia' => data_get($validated, 'ecf.company.provincia'),
            'ecf.pending_alert_hours' => (int) data_get($validated, 'ecf.pending_alert_hours'),
            'ecf.receiver_base_url' => data_get($validated, 'ecf.receiver_base_url'),
        ];
        // A blank key keeps the stored one; the plain key is never written, logged or echoed back.
        $apiKey = data_get($validated, 'ecf.alanube.api_key');
        if (filled($apiKey)) {
            $flat['ecf.alanube.api_key'] = Crypt::encryptString($apiKey);
        }
        // The webhook key is generated once, when Alanube becomes the provider, and then shown on the settings page.
        if (data_get($validated, 'ecf.provider') === 'alanube' && blank($settings->get('ecf.alanube.webhook_key'))) {
            $flat['ecf.alanube.webhook_key'] = Str::random(40);
        }

        foreach ($flat as $key => $value) {
            $settings->set($key, $value);
        }
        activity('settings')->causedBy($request->user())->event('updated')->withProperties(['keys' => array_keys($flat)])->log('Facturación electrónica actualizada');

        return redirect()->route('admin.ecf-settings.index')->with('success', 'Configuración de facturación electrónica guardada');
    }

    #[Middleware('permission:fiscal.ecf.settings')]
    public function storeCertificate(StoreEcfCertificateRequest $request, CertificateStore $certificateStore): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $certificate = $certificateStore->store((string) $request->file('certificate')->get(), (string) $validated['password'], $this->actor($request), $validated['name'] ?? null);
        } catch (EcfCertificateException $exception) {
            return back()->withErrors(['certificate' => $exception->getMessage()]);
        }

        $expiresOn = $certificate->valid_to->copy()->setTimezone('America/Santo_Domingo')->format('d/m/Y');

        return redirect()->route('admin.ecf-settings.index')->with('success', "Certificado {$certificate->subject} cargado y activado (vence el {$expiresOn})");
    }

    #[Middleware('permission:fiscal.ecf.settings')]
    public function deactivateCertificate(EcfCertificate $ecfCertificate, CertificateStore $certificateStore, Request $request): RedirectResponse
    {
        $certificateStore->deactivate($ecfCertificate, $this->actor($request));

        return redirect()->route('admin.ecf-settings.index')->with('success', 'Certificado desactivado');
    }

    /** @return array<string, string|int|null> */
    private function values(Settings $settings): array
    {
        return [
            'fiscal.mode' => $settings->get('fiscal.mode', 'ncf'),
            'fiscal.ecf_cutover_date' => $settings->get('fiscal.ecf_cutover_date'),
            'ecf.provider' => $settings->get('ecf.provider', 'fake'),
            'ecf.environment' => $settings->get('ecf.environment', 'test'),
            'ecf.rfce_threshold' => $settings->get('ecf.rfce_threshold', config('ecf.rfce_threshold')),
            'ecf.company.municipio' => $settings->get('ecf.company.municipio'),
            'ecf.company.provincia' => $settings->get('ecf.company.provincia'),
            'ecf.pending_alert_hours' => $settings->get('ecf.pending_alert_hours', 24),
            'ecf.receiver_base_url' => $settings->get('ecf.receiver_base_url', config('app.url')),
        ];
    }

    /** @return array{id: int, name: string, subject: string, issuer: string, serial_number: string, rnc: string|null, valid_from: string, valid_to: string, is_active: bool, is_expired: bool} */
    private function certificateSummary(EcfCertificate $certificate): array
    {
        return [
            'id' => $certificate->id,
            'name' => $certificate->name,
            'subject' => $certificate->subject,
            'issuer' => $certificate->issuer,
            'serial_number' => $certificate->serial_number,
            'rnc' => $certificate->rnc,
            'valid_from' => $certificate->valid_from->toIso8601String(),
            'valid_to' => $certificate->valid_to->toIso8601String(),
            'is_active' => $certificate->is_active,
            'is_expired' => $certificate->isExpired(),
        ];
    }

    /** @return list<string> */
    private function warnings(?EcfCertificate $certificate, string $companyRnc): array
    {
        if ($certificate === null) {
            return [];
        }

        $warnings = [];
        if ($certificate->rnc !== null && $companyRnc !== '' && $certificate->rnc !== FiscalId::normalize($companyRnc)) {
            $warnings[] = "El certificado pertenece al RNC {$certificate->rnc}, distinto al RNC de la empresa {$companyRnc}.";
        }
        if ($certificate->isExpired()) {
            $warnings[] = 'El certificado venció el '.$certificate->valid_to->copy()->setTimezone('America/Santo_Domingo')->format('d/m/Y').'.';
        }

        return $warnings;
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
