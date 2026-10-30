@extends('pdf.layout')

@section('document-box')
<div class="box"><strong style="font-size: 14px;">PROFORMA</strong><br><strong>{{ $proforma->number }}</strong><br>Fecha: {{ $proforma->issue_date->format('d/m/Y') }}<br>Válida hasta: {{ $proforma->valid_until->format('d/m/Y') }}<br>Vendedor: {{ $proforma->salesperson->name }}<br>Moneda: {{ $currency }}</div>
@endsection

@section('content')
@include('pdf.partials.client-block', ['client' => $proforma->client])
@include('pdf.partials.lines-table')
@include('pdf.partials.totals')
@if($proforma->notes)<p class="muted">{{ $proforma->notes }}</p>@endif
<p class="muted" style="margin-top: 12px;">Documento sin valor fiscal. Precios sujetos a cambio después de la fecha de validez.</p>
@endsection
