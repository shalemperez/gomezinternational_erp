<table class="totals" style="width: 45%; margin-left: 55%; margin-top: 8px;">
    <tr><td>Subtotal</td><td class="num">{{ \App\Services\Documents\DocumentPdf::money($totals['subtotal'], $currency) }}</td></tr>
    <tr><td>Descuento</td><td class="num">{{ \App\Services\Documents\DocumentPdf::money($totals['discount_total'], $currency) }}</td></tr>
    <tr><td>Base imponible</td><td class="num">{{ \App\Services\Documents\DocumentPdf::money($totals['taxable_total'], $currency) }}</td></tr>
    @if(! \App\Support\Money\Decimal::isZero($totals['additional_tax_total']))<tr><td>Impuestos adicionales</td><td class="num">{{ \App\Services\Documents\DocumentPdf::money($totals['additional_tax_total'], $currency) }}</td></tr>@endif
    <tr><td>ITBIS</td><td class="num">{{ \App\Services\Documents\DocumentPdf::money($totals['itbis_total'], $currency) }}</td></tr>
    <tr class="grand"><td>Total</td><td class="num">{{ \App\Services\Documents\DocumentPdf::money($totals['total'], $currency) }}</td></tr>
</table>
