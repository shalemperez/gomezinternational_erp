@extends('pdf.layout')

@section('document-box')
@include('pdf.partials.fiscal-box', ['title' => $ecf['type_label'] ?? 'Nota de Débito', 'ncf' => $debitNote->ncf, 'ncfLabel' => $ecf !== null ? 'e-NCF' : 'NCF', 'expiresOn' => $ecf !== null ? $ecf['expires_on'] : $debitNote->ncf_expires_on?->format('d/m/Y'), 'modifiedNcf' => $debitNote->modified_ncf, 'modificationLabel' => $ecf['modification_label'] ?? null, 'number' => $debitNote->number, 'issueDate' => $debitNote->issue_date->format('d/m/Y'), 'dueDate' => null, 'extra' => ['Motivo' => $debitNote->reason->label(), 'Factura' => $debitNote->invoice->number, 'Moneda' => $currency]])
@endsection

@section('content')
<div class="box" style="margin: 8px 0;"><strong>Cliente:</strong> {{ $debitNote->invoice->client_name }}<br>@if($debitNote->invoice->client_tax_id)<strong>{{ \App\Enums\TaxIdType::tryFrom((string) $debitNote->invoice->client_tax_id_type)?->label() ?? 'RNC' }}:</strong> {{ \App\Support\FiscalId::format($debitNote->invoice->client_tax_id) }}<br>@endif @if($debitNote->invoice->client_address){{ $debitNote->invoice->client_address }}<br>@endif</div>
@include('pdf.partials.lines-table', ['ecf' => $ecf !== null])
@include('pdf.partials.totals')
@if($debitNote->notes)<p class="muted">{{ $debitNote->notes }}</p>@endif
<p class="muted" style="margin-top: 10px;">{{ $copy }}</p>
@if($ecf !== null)@include('pdf.partials.ecf-stamp', ['ecf' => $ecf])@endif
@endsection
