<?php

namespace App\Services\Ecf;

use App\Enums\EcfEnvironment;
use App\Exceptions\EcfException;
use App\Services\Settings;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client of the Alanube PSFE API (RES §12). Every request carries the integrator's bearer key.
 */
class AlanubeClient
{
    public function __construct(
        private readonly Settings $settings,
        private readonly EcfService $ecf,
    ) {}

    public function baseUrl(): string
    {
        $environment = $this->ecf->environment() === EcfEnvironment::Prod ? 'production' : 'sandbox';

        return (string) config("ecf.alanube.base_urls.{$environment}");
    }

    /** @throws EcfException when the API key is not configured */
    public function apiKey(): string
    {
        $encryptedKey = $this->settings->get('ecf.alanube.api_key');
        if (blank($encryptedKey)) {
            throw new EcfException('Configura la API key de Alanube en Configuración → Facturación electrónica.');
        }

        return Crypt::decryptString((string) $encryptedKey);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws EcfException when the request is rejected, fails or cannot reach Alanube
     */
    public function post(string $path, array $payload): array
    {
        $token = $this->apiKey();

        return $this->send(
            fn (): Response => Http::withToken($token)->acceptJson()->timeout((int) config('ecf.http_timeout_seconds'))->post($this->url($path), $payload),
            201,
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws EcfException when the request is rejected, fails or cannot reach Alanube
     */
    public function get(string $path): array
    {
        $token = $this->apiKey();

        return $this->send(
            fn (): Response => Http::withToken($token)->acceptJson()->timeout((int) config('ecf.http_timeout_seconds'))->get($this->url($path)),
            200,
        );
    }

    private function url(string $path): string
    {
        return "{$this->baseUrl()}/{$path}";
    }

    /**
     * @param  Closure(): Response  $call
     * @return array<string, mixed>
     *
     * @throws EcfException
     */
    private function send(Closure $call, int $expectedStatus): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $exception) {
            throw new EcfException("No se pudo conectar con Alanube: {$exception->getMessage()}");
        }

        if ($response->status() === $expectedStatus) {
            $json = $response->json();

            return is_array($json) ? $json : [];
        }

        if ($response->clientError()) {
            $reason = $response->json('message') ?? $response->json('code');

            throw new EcfException("Alanube rechazó la solicitud ({$response->status()}): ".(is_scalar($reason) ? (string) $reason : ''));
        }

        throw new EcfException("Error HTTP {$response->status()} de Alanube");
    }
}
