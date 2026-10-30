@extends('pdf.layout')

@section('document-box')
@include('pdf.partials.fiscal-box', ['title' => $ecf['type_label'] ?? 'Nota de Crédito', 'ncf' => $creditNote->ncf, 'ncfLabel' => $ecf !== null ? 'e-NCF' : 'NCF', 'expiresOn' => $ecf !== null ? $ecf['expires_on'] : $creditNote->ncf_expires_on?->format('d/m/Y'), 'modifiedNcf' => $creditNote->modified_ncf, 'modificationLabel' => $ecf['modification_label'] ?? null, 'number' => $creditNote->number, 'issueDate' => $creditNote->issue_date->format('d/m/Y'), 'dueDate' => null, 'extra' => ['Motivo' => $creditNote->reason->label(), 'Factura' => $creditNote->invoice->number, 'Moneda' => $currency]])
@endsection

@section('content')
<div class="box" style="margin: 8px 0;"><strong>Cliente:</strong> {{ $creditNote->invoice->client_name }}<br>@if($creditNote->invoice->client_tax_id)<strong>{{ \App\Enums\TaxIdType::tryFrom((string) $creditNote->invoice->client_tax_id_type)?->label() ?? 'RNC' }}:</strong> {{ \App\Support\FiscalId::format($creditNote->invoice->client_tax_id) }}<br>@endif @if($creditNote->invoice->client_address){{ $creditNote->invoice->client_address }}<br>@endif</div>
@include('pdf.partials.lines-table', ['ecf' => $ecf !== null])
@include('pdf.partials.totals')
@if($creditNote->notes)<p class="muted">{{ $creditNote->notes }}</p>@endif
<p class="muted" style="margin-top: 10px;">{{ $copy }}</p>
@if($ecf !== null)@include('pdf.partials.ecf-stamp', ['ecf' => $ecf])@endif
@endsection
