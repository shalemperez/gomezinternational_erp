<?php

namespace App\Support\Ecf;

use App\Enums\EcfEnvironment;
use Carbon\CarbonInterface;

final class EcfQrUrl
{
    /** Consulta timbre URL of a full e-CF (GC-17 QR). */
    public static function forEcf(EcfEnvironment $environment, string $rncEmisor, ?string $rncComprador, string $encf, CarbonInterface $fechaEmision, string $montoTotal, CarbonInterface $fechaFirma, string $codigoSeguridad): string
    {
        $parameters = ['rncemisor' => $rncEmisor];
        if ($rncComprador !== null) {
            $parameters['rnccomprador'] = $rncComprador;
        }
        $parameters += [
            'encf' => $encf,
            'fechaemision' => $fechaEmision->format('d-m-Y'),
            'montototal' => $montoTotal,
            'fechafirma' => $fechaFirma->format('d-m-Y H:i:s'),
            'codigoseguridad' => $codigoSeguridad,
        ];

        return self::build(config("ecf.environments.{$environment->value}.consulta_timbre"), $parameters);
    }

    /** Consulta timbre URL of a RFCE (consumo under the RD$250,000 threshold). */
    public static function forRfce(EcfEnvironment $environment, string $rncEmisor, string $encf, string $montoTotal, string $codigoSeguridad): string
    {
        return self::build(config("ecf.environments.{$environment->value}.consulta_timbre_fc"), [
            'rncemisor' => $rncEmisor,
            'encf' => $encf,
            'montototal' => $montoTotal,
            'codigoseguridad' => $codigoSeguridad,
        ]);
    }

    /** @param  array<string, string>  $parameters */
    private static function build(string $baseUrl, array $parameters): string
    {
        $query = collect($parameters)->map(fn (string $value, string $name) => "{$name}=".rawurlencode($value))->implode('&');

        return "{$baseUrl}?{$query}";
    }
}
