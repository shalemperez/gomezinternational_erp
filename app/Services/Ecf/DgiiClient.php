<?php

namespace App\Services\Ecf;

use App\Exceptions\EcfException;
use App\Models\EcfCertificate;
use Closure;
use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client of the DGII web services. Every authenticated call uses the bearer token of the seed flow; a 401 refreshes
 * the token once and retries.
 */
class DgiiClient
{
    public function __construct(
        private readonly CertificateStore $certificates,
        private readonly XmlSigner $signer,
        private readonly XsdValidator $xsd,
        private readonly EcfService $ecf,
    ) {}

    /** @throws EcfException when the environment has no endpoint for the key */
    public function endpoint(string $key): string
    {
        return config("ecf.environments.{$this->ecf->environment()->value}.{$key}");
    }

    /** @throws EcfException when the DGII does not accept the seed */
    public function token(): string
    {
        $certificate = $this->certificates->requireActive();

        return Cache::remember($this->tokenKey($certificate), now()->addMinutes((int) config('ecf.token_ttl_minutes')), fn (): string => $this->authenticate());
    }

    /**
     * Seed flow: GET the seed, sign it with the active certificate, validate it against the XSD and POST it back.
     *
     * @throws EcfException when the DGII does not accept the seed
     */
    public function authenticate(): string
    {
        $certificate = $this->certificates->requireActive();

        $seedResponse = $this->request(fn (): Response => Http::timeout((int) config('ecf.http_timeout_seconds'))->get($this->endpoint('semilla')), 'semilla');
        $seed = new DOMDocument;
        if (! $seed->loadXML($seedResponse->body())) {
            throw new EcfException('La DGII devolvió una semilla no válida.');
        }

        $this->signer->sign($seed, $certificate);
        $this->xsd->validate($seed, 'semilla');

        $response = $this->request(fn (): Response => Http::timeout((int) config('ecf.http_timeout_seconds'))
            ->attach('xml', (string) $seed->saveXML(), 'semilla.xml', ['Content-Type' => 'text/xml'])
            ->post($this->endpoint('validar_semilla')), 'validar_semilla');

        if (! $response->successful()) {
            throw new EcfException("La DGII no aceptó la autenticación ({$response->status()}): ".$this->excerpt($response));
        }

        $token = $response->json('token');
        if (! is_string($token) || $token === '') {
            throw new EcfException('La DGII no devolvió un token de autenticación.');
        }

        return $token;
    }

    public function forgetToken(): void
    {
        Cache::forget($this->tokenKey($this->certificates->requireActive()));
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     *
     * @throws EcfException on connection failure, HTTP 5xx or a 401 that persists after the token refresh
     */
    public function receive(string $signedXml, string $fileName): array
    {
        return $this->post('recepcion', $signedXml, $fileName);
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     *
     * @throws EcfException on connection failure, HTTP 5xx or a 401 that persists after the token refresh
     */
    public function receiveRfce(string $signedXml, string $fileName): array
    {
        return $this->post('recepcion_fc', $signedXml, $fileName);
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     *
     * @throws EcfException on connection failure, HTTP 5xx or a 401 that persists after the token refresh
     */
    public function commercialApproval(string $signedXml, string $fileName): array
    {
        return $this->post('aprobacion_comercial', $signedXml, $fileName);
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     *
     * @throws EcfException on connection failure, HTTP 5xx or a 401 that persists after the token refresh
     */
    public function annulRange(string $signedXml, string $fileName): array
    {
        return $this->post('anulacion_rangos', $signedXml, $fileName);
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     *
     * @throws EcfException on connection failure, HTTP 5xx or a 401 that persists after the token refresh
     */
    public function result(string $trackId): array
    {
        $response = $this->authorized(fn (string $token): Response => Http::withToken($token)->acceptJson()
            ->timeout((int) config('ecf.http_timeout_seconds'))
            ->get($this->endpoint('consulta_resultado'), ['trackid' => $trackId]), 'consulta_resultado');

        return $this->summary($response);
    }

    /**
     * The receiver's directory entry for an RNC, or null when the taxpayer is not listed.
     *
     * @return array<string, mixed>|null
     *
     * @throws EcfException on connection failure, HTTP 5xx or a 401 that persists after the token refresh
     */
    public function directoryByRnc(string $rnc): ?array
    {
        $response = $this->authorized(fn (string $token): Response => Http::withToken($token)->acceptJson()
            ->timeout((int) config('ecf.http_timeout_seconds'))
            ->get($this->endpoint('directorio_por_rnc'), ['RNC' => $rnc]), 'directorio_por_rnc');

        $entries = $response->json();

        return is_array($entries) && isset($entries[0]) && is_array($entries[0]) ? $entries[0] : null;
    }

    /**
     * @return array{status: int, json: array<string, mixed>}
     *
     * @throws EcfException on connection failure, HTTP 5xx or a 401 that persists after the token refresh
     */
    private function post(string $key, string $signedXml, string $fileName): array
    {
        $response = $this->authorized(fn (string $token): Response => Http::withToken($token)->acceptJson()
            ->timeout((int) config('ecf.http_timeout_seconds'))
            ->attach('xml', $signedXml, $fileName, ['Content-Type' => 'text/xml'])
            ->post($this->endpoint($key)), $key);

        return $this->summary($response);
    }

    /**
     * Sends with the current token; on 401 forgets the token, sends again and rejects a second 401.
     *
     * @param  Closure(string): Response  $send
     *
     * @throws EcfException
     */
    private function authorized(Closure $send, string $service): Response
    {
        $response = $this->request(fn (): Response => $send($this->token()), $service);
        if ($response->status() !== 401) {
            return $response;
        }

        $this->forgetToken();
        $response = $this->request(fn (): Response => $send($this->token()), $service);
        if ($response->status() === 401) {
            throw new EcfException("La DGII rechazó el token de autenticación (401) en {$service}.");
        }

        return $response;
    }

    /**
     * @param  Closure(): Response  $send
     *
     * @throws EcfException on connection failure or HTTP 5xx
     */
    private function request(Closure $send, string $service): Response
    {
        try {
            $response = $send();
        } catch (ConnectionException $e) {
            throw new EcfException("No se pudo conectar con la DGII ({$service}): {$e->getMessage()}", 0, $e);
        }

        if ($response->serverError()) {
            throw new EcfException("Error HTTP {$response->status()} de la DGII en {$service}: ".$this->excerpt($response));
        }

        return $response;
    }

    /** @return array{status: int, json: array<string, mixed>} */
    private function summary(Response $response): array
    {
        $json = $response->json();

        return ['status' => $response->status(), 'json' => is_array($json) ? $json : []];
    }

    private function excerpt(Response $response): string
    {
        return mb_substr($response->body(), 0, 300);
    }

    private function tokenKey(EcfCertificate $certificate): string
    {
        return "ecf.token.{$this->ecf->environment()->value}.{$certificate->fingerprint_sha256}";
    }
}
