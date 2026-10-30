<?php

use App\Services\Documents\DocumentPdf;
use App\Services\Settings;

test('renders a pdf with the company header and spanish money format', function () {
    app(Settings::class)->set('company.name', 'Gomez International');
    app(Settings::class)->set('company.rnc', '101850043');
    $pdf = app(DocumentPdf::class);

    $binary = $pdf->binary('pdf.probe', ['title' => 'Prueba', 'lines' => [['description' => 'Ron añejo', 'quantity' => '2.0000', 'unit_price' => '100.0000', 'discount_percent' => '0.0000', 'itbis_amount' => '36.00', 'additional_tax_total' => '0.00', 'line_total' => '236.00']], 'totals' => ['subtotal' => '200.00', 'discount_total' => '0.00', 'taxable_total' => '200.00', 'itbis_total' => '36.00', 'additional_tax_total' => '0.00', 'total' => '236.00'], 'currency' => 'DOP'], 'prueba.pdf');

    expect(substr($binary, 0, 4))->toBe('%PDF')->and(strlen($binary))->toBeGreaterThan(1000)
        ->and(DocumentPdf::money('1234.5', 'DOP'))->toBe('RD$ 1,234.50')->and(DocumentPdf::money('1234.5', 'USD'))->toBe('US$ 1,234.50')
        ->and($pdf->company()['name'])->toBe('Gomez International')->and($pdf->company()['rnc_formatted'])->toBe('1-01-85004-3');
});

test('pdf numbers are formatted without floats', function () {
    expect(DocumentPdf::number('1234567.885'))->toBe('1,234,567.89')
        ->and(DocumentPdf::number('-0.5'))->toBe('-0.50')
        ->and(DocumentPdf::money('1000', 'USD'))->toBe('US$ 1,000.00');
});
