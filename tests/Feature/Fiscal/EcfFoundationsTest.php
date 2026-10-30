<?php

use App\Models\Invoice;
use Illuminate\Support\Facades\Schema;

test('fiscal columns accept thirteen character e-ncfs and ten digit sequence ranges', function () {
    $sequence = activeSequence('E31', ['range_from' => 1, 'range_to' => 9999999999, 'next_number' => 1, 'environment' => 'test']);
    $invoice = Invoice::factory()->create(['ncf' => 'E310000000001', 'ncf_type' => 'E31', 'fiscal_mode' => 'ecf']);

    expect($invoice->fresh()->ncf)->toBe('E310000000001')
        ->and($sequence->fresh()->range_to)->toBe(9999999999)
        ->and(collect(Schema::getColumns('credit_notes'))->firstWhere('name', 'modified_ncf')['type'])->toBe('character varying(13)')
        ->and(collect(Schema::getColumns('invoices'))->firstWhere('name', 'ncf')['type'])->toBe('character varying(13)')
        ->and(collect(Schema::getColumns('ncf_sequences'))->firstWhere('name', 'environment')['default'])->toContain('prod');
});

test('phase 3 tables exist with their key columns', function () {
    expect(Schema::hasColumns('ecf_certificates', ['p12_encrypted', 'password_encrypted', 'serial_number', 'valid_to', 'is_active']))->toBeTrue()
        ->and(Schema::hasColumns('ecf_documents', ['document_type', 'document_id', 'ncf_type', 'encf', 'environment', 'provider', 'flow', 'status', 'track_id', 'security_code', 'signed_at', 'xml_path', 'rfce_xml_path', 'dgii_code', 'dgii_messages', 'attempts', 'next_attempt_at', 'commercial_status']))->toBeTrue()
        ->and(Schema::hasColumns('ecf_transmissions', ['ecf_document_id', 'kind', 'http_status', 'response', 'response_path', 'succeeded', 'error']))->toBeTrue()
        ->and(Schema::hasColumns('received_ecfs', ['rnc_emisor', 'encf', 'monto_total', 'xml_path', 'arecf_path', 'arecf_status', 'commercial_status']))->toBeTrue()
        ->and(Schema::hasColumns('ecf_annulments', ['status', 'xml_path', 'response']))->toBeTrue()
        ->and(Schema::hasColumns('ecf_annulment_ranges', ['ecf_annulment_id', 'ncf_type', 'range_from', 'range_to', 'quantity']))->toBeTrue();
});

test('ecf config exposes the dgii endpoints per environment and the xsd directory', function () {
    expect(config('ecf.environments.test.recepcion'))->toBe('https://ecf.dgii.gov.do/testecf/recepcion/api/facturaselectronicas')
        ->and(config('ecf.environments.prod.semilla'))->toBe('https://ecf.dgii.gov.do/ecf/autenticacion/api/autenticacion/semilla')
        ->and(config('ecf.environments.cert.recepcion_fc'))->toBe('https://fc.dgii.gov.do/certecf/recepcionfc/api/recepcion/ecf')
        ->and(config('ecf.environments.prod.consulta_timbre'))->toBe('https://ecf.dgii.gov.do/ecf/consultatimbre')
        ->and(config('ecf.environments.test.consulta_timbre_fc'))->toBe('https://fc.dgii.gov.do/testecf/consultatimbrefc')
        ->and(is_file(config('ecf.xsd_path').'/e-CF 31 v.1.0.xsd'))->toBeTrue()
        ->and(config('ecf.unit_codes.UND'))->toBe(43)->and(config('ecf.unit_codes.default'))->toBe(43)
        ->and(config('ecf.token_ttl_minutes'))->toBe(50);
});
