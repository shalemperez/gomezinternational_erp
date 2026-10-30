@extends('pdf.layout')

@section('document-box')
<div class="box">
    <strong style="font-size:14px;">ESTADO DE CUENTA</strong><br>
    Al: {{ $to->format('d/m/Y') }}<br>
    Desde: {{ $from->format('d/m/Y') }}
</div>
@endsection

@section('content')
@include('pdf.partials.client-block', ['client' => $client])
@forelse($statement as $currency => $section)
<p style="font-weight: bold; margin: 10px 0 4px;">Moneda: {{ $currency }}</p>
<table class="lines">
    <thead>
        <tr>
            <th>Fecha</th>
            <th>Tipo</th>
            <th>Número</th>
            <th>NCF</th>
            <th>Descripción</th>
            <th class="num">Débito</th>
            <th class="num">Crédito</th>
            <th class="num">Balance</th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td colspan="7" class="muted">Saldo inicial</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::money($section['opening_balance'], $currency) }}</td>
        </tr>
        @foreach($section['rows'] as $row)
        <tr>
            <td>{{ \Carbon\CarbonImmutable::parse($row['date'])->format('d/m/Y') }}</td>
            <td>{{ $row['type_label'] }}</td>
            <td>{{ $row['number'] }}</td>
            <td>{{ $row['ncf'] }}</td>
            <td>{{ $row['description'] }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::money($row['debit'], $currency) }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::money($row['credit'], $currency) }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::money($row['balance'], $currency) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
<table class="totals">
    <tr class="grand">
        <td style="width: 80%;">Saldo final</td>
        <td class="num">{{ \App\Services\Documents\DocumentPdf::money($section['closing_balance'], $currency) }}</td>
    </tr>
</table>
@empty
<p class="muted" style="margin-top: 10px;">Sin movimientos en el período.</p>
@endforelse

@if(count($aging) > 0)
<p style="font-weight: bold; margin: 14px 0 4px;">Antigüedad al {{ $to->format('d/m/Y') }}</p>
<table class="lines">
    <thead>
        <tr>
            <th>Moneda</th>
            <th class="num">Vigente</th>
            <th class="num">1–30</th>
            <th class="num">31–60</th>
            <th class="num">61–90</th>
            <th class="num">+90</th>
            <th class="num">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($aging as $row)
        <tr>
            <td>{{ $row['currency_code'] }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::money($row['current'], $row['currency_code']) }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::money($row['d1_30'], $row['currency_code']) }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::money($row['d31_60'], $row['currency_code']) }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::money($row['d61_90'], $row['currency_code']) }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::money($row['d90_plus'], $row['currency_code']) }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::money($row['total'], $row['currency_code']) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif
@endsection
