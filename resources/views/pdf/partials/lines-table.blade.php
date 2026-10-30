@php($withEcfColumns = $ecf ?? false)
<table class="lines">
    <thead><tr><th>Descripción</th>@if($withEcfColumns)<th>Unidad</th>@endif<th class="num">Cant.</th><th class="num">Precio</th><th class="num">Desc. %</th><th class="num">ITBIS</th><th class="num">Imp. adic.</th><th class="num">Total</th></tr></thead>
    <tbody>
    @foreach($lines as $line)
        <tr>
            <td>@if($withEcfColumns && \App\Support\Money\Decimal::isZero($line['itbis_rate']))E @endif{{ $line['description'] }}</td>
            @if($withEcfColumns)<td>{{ $line['unit'] }}</td>@endif
            <td class="num">{{ \App\Services\Documents\DocumentPdf::number($line['quantity']) }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::number($line['unit_price']) }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::number($line['discount_percent']) }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::number($line['itbis_amount']) }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::number($line['additional_tax_total']) }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::number($line['line_total']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
