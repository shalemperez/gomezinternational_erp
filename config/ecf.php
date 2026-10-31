<?php

$dgii = fn (string $segment): array => [
    'semilla' => "https://ecf.dgii.gov.do/{$segment}/autenticacion/api/autenticacion/semilla",
    'validar_semilla' => "https://ecf.dgii.gov.do/{$segment}/autenticacion/api/autenticacion/validarsemilla",
    'recepcion' => "https://ecf.dgii.gov.do/{$segment}/recepcion/api/facturaselectronicas",
    'recepcion_fc' => "https://fc.dgii.gov.do/{$segment}/recepcionfc/api/recepcion/ecf",
    'consulta_resultado' => "https://ecf.dgii.gov.do/{$segment}/consultaresultado/api/consultas/estado",
    'consulta_trackids' => "https://ecf.dgii.gov.do/{$segment}/consultatrackids/api/trackids/consulta",
    'consulta_estado' => "https://ecf.dgii.gov.do/{$segment}/consultaestado/api/consultas/estado",
    'aprobacion_comercial' => "https://ecf.dgii.gov.do/{$segment}/aprobacioncomercial/api/aprobacioncomercial",
    'anulacion_rangos' => "https://ecf.dgii.gov.do/{$segment}/anulacionrangos/api/operaciones/anularrango",
    'directorio_por_rnc' => "https://ecf.dgii.gov.do/{$segment}/consultadirectorio/api/consultas/obtenerdirectorioporrnc",
    'consulta_timbre' => "https://ecf.dgii.gov.do/{$segment}/consultatimbre",
    'consulta_timbre_fc' => "https://fc.dgii.gov.do/{$segment}/consultatimbrefc",
];

return [
    // DGII web services per environment (Descripción Técnica Servicios DGII, bitácora 02-01-2026).
    // Consulta TrackIds exists only in TesteCF and eCF (RES §4); in CerteCF a resend is sent without lookup.
    'environments' => ['test' => $dgii('testecf'), 'cert' => array_diff_key($dgii('certecf'), ['consulta_trackids' => true]), 'prod' => $dgii('ecf')],
    'xsd_path' => resource_path('xsd/dgii'),
    'storage_disk' => 'local',
    'token_ttl_minutes' => 50, // DGII tokens last 1 hour; refresh early
    'http_timeout_seconds' => 30,
    'rfce_threshold' => '250000.00',
    'retry_backoff_minutes' => [1, 5, 15, 60, 120, 240, 480],
    'poll_delay_seconds' => 30,
    'max_attempts' => 48,
    'pending_alert_hours' => 24,
    // Public /fe endpoints (GC-26).
    'receiver' => [
        'rate_per_minute' => 60,
        'max_bytes' => 1048576,
        'refused_per_ip_per_day' => 100,
        // RES §3: the certificate SN may be the delegate's cédula; set false during certification if real supplier certificates do not carry the RNC.
        'verify_signer_tax_id' => (bool) env('ECF_VERIFY_SIGNER_TAX_ID', true),
    ],
    'timezone' => 'America/Santo_Domingo',
    // Tabla IV "Codificación Unidad de Medida" by the ERP unit code; unknown codes use 'default' (43 = Unidad).
    'unit_codes' => ['UND' => 43, 'CAJ' => 6, 'BOT' => 5, 'LT' => 24, 'L' => 24, 'KG' => 21, 'DOC' => 13, 'PAQ' => 31, 'PZA' => 34, 'GR' => 17, 'LB' => 23, 'GL' => 15, 'ML' => 59, 'default' => 43],
    'alanube' => [
        'base_urls' => ['sandbox' => env('ALANUBE_SANDBOX_URL', 'https://sandbox.alanube.co/dom/v1'), 'production' => env('ALANUBE_PRODUCTION_URL', 'https://api.alanube.co/dom/v1')],
        'paths' => ['E31' => 'fiscal-invoices', 'E32' => 'invoices', 'E33' => 'debit-notes', 'E34' => 'credit-notes', 'E44' => 'special-regimes', 'E45' => 'gubernamentals'],
    ],
];
