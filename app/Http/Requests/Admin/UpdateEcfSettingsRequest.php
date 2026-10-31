<?php

namespace App\Http\Requests\Admin;

use App\Enums\EcfEnvironment;
use App\Enums\NcfType;
use App\Models\EcfDocument;
use App\Models\NcfSequence;
use App\Services\Ecf\CertificateStore;
use App\Services\Settings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateEcfSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fiscal.mode' => ['required', 'in:ncf,ecf'],
            'fiscal.ecf_cutover_date' => ['nullable', 'date_format:Y-m-d'],
            'ecf.provider' => ['required', 'in:fake,dgii,alanube'],
            'ecf.environment' => ['required', 'in:test,cert,prod'],
            'ecf.rfce_threshold' => ['required', 'numeric', 'min:0', 'max:999999999999.99'],
            'ecf.company.municipio' => ['nullable', 'digits:6'],
            'ecf.company.provincia' => ['nullable', 'digits:6'],
            'ecf.pending_alert_hours' => ['required', 'integer', 'between:1,72'],
            'ecf.receiver_base_url' => ['nullable', 'url', 'max:200'],
            'ecf.alanube.api_key' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $provider = $this->string('ecf.provider')->toString();
            $environment = $this->string('ecf.environment')->toString();

            if ($provider === 'fake' && app()->isProduction()) {
                $v->errors()->add('ecf.provider', 'El proveedor simulado no se permite en producción.');
            }

            $existing = EcfDocument::query()->where('environment', '!=', $environment)->first();
            if ($existing !== null) {
                $v->errors()->add('ecf.environment', "Esta base de datos ya tiene e-CF del ambiente {$existing->environment->label()}; usa una instalación separada para certificación o pruebas.");
            }

            if ($this->string('fiscal.mode')->toString() !== 'ecf') {
                return;
            }

            if ($provider === 'dgii') {
                $certificate = app(CertificateStore::class)->current();
                if ($certificate === null || $certificate->isExpired()) {
                    $v->errors()->add('fiscal.mode', 'Para activar el modo e-CF con DGII directo carga un certificado digital vigente.');
                }
            }

            $hasAlanubeKey = $this->filled('ecf.alanube.api_key') || filled(app(Settings::class)->get('ecf.alanube.api_key'));
            if ($provider === 'alanube' && ! $hasAlanubeKey) {
                $v->errors()->add('fiscal.mode', 'Para activar el modo e-CF con Alanube registra la API key.');
            }

            $environmentLabel = EcfEnvironment::tryFrom($environment)?->label() ?? $environment;
            foreach ([NcfType::E31, NcfType::E32] as $type) {
                $hasSequence = NcfSequence::query()->where('ncf_type', $type->value)->where('environment', $environment)->where('is_active', true)->exists();
                if (! $hasSequence) {
                    $v->errors()->add('fiscal.mode', "Registra secuencias e-NCF activas (E31 y E32) para el ambiente {$environmentLabel} antes de activar el modo e-CF.");

                    return;
                }
            }
        });
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'fiscal.mode' => 'el modo fiscal', 'fiscal.ecf_cutover_date' => 'la fecha de cambio', 'ecf.provider' => 'el proveedor',
            'ecf.environment' => 'el ambiente', 'ecf.rfce_threshold' => 'el umbral de RFCE', 'ecf.company.municipio' => 'el municipio',
            'ecf.company.provincia' => 'la provincia', 'ecf.pending_alert_hours' => 'las horas de alerta', 'ecf.receiver_base_url' => 'la URL base de recepción',
            'ecf.alanube.api_key' => 'la API key de Alanube',
        ];
    }
}
