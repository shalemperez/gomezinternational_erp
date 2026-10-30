@extends('pdf.layout')

@section('document-box')
@include('pdf.partials.fiscal-box', ['title' => 'Nota de Crédito', 'ncf' => $creditNote->ncf, 'expiresOn' => $creditNote->ncf_expires_on?->format('d/m/Y'), 'modifiedNcf' => $creditNote->modified_ncf, 'number' => $creditNote->number, 'issueDate' => $creditNote->issue_date->format('d/m/Y'), 'dueDate' => null, 'extra' => ['Motivo' => $creditNote->reason->label(), 'Factura' => $creditNote->invoice->number, 'Moneda' => $currency]])
@endsection

@section('content')
<div class="box" style="margin: 8px 0;"><strong>Cliente:</strong> {{ $creditNote->invoice->client_name }}<br>@if($creditNote->invoice->client_tax_id)<strong>{{ \App\Enums\TaxIdType::tryFrom((string) $creditNote->invoice->client_tax_id_type)?->label() ?? 'RNC' }}:</strong> {{ \App\Support\FiscalId::format($creditNote->invoice->client_tax_id) }}<br>@endif @if($creditNote->invoice->client_address){{ $creditNote->invoice->client_address }}<br>@endif</div>
@include('pdf.partials.lines-table')
@include('pdf.partials.totals')
@if($creditNote->notes)<p class="muted">{{ $creditNote->notes }}</p>@endif
<p class="muted" style="margin-top: 10px;">{{ $copy }}</p>
@endsection
