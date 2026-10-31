@extends('pdf.layout')

@section('document-box')
@include('pdf.partials.fiscal-box', ['title' => $ecf['type_label'] ?? ($invoice->kind->value === 'historical' ? 'Factura histórica (registrada externamente)' : ($invoice->ncf_type?->label() ?? 'Factura')), 'ncf' => $invoice->ncf, 'ncfLabel' => $ecf !== null ? 'e-NCF' : 'NCF', 'expiresOn' => $ecf !== null ? $ecf['expires_on'] : $invoice->ncf_expires_on?->format('d/m/Y'), 'modifiedNcf' => null, 'number' => $invoice->number, 'issueDate' => $invoice->issue_date->format('d/m/Y'), 'dueDate' => $invoice->payment_terms->value === 'credit' ? $invoice->due_date->format('d/m/Y') : null, 'extra' => ['Condición' => $invoice->payment_terms->label(), 'Vendedor' => $invoice->salesperson->name, 'Moneda' => $currency]])
@if($invoice->status->value === 'cancelled')
<div style="color:#b00; font-weight:bold; font-size:16px;">ANULADA</div>
@endif
@endsection

@section('content')
@if($invoice->issued_at)
<div class="box" style="margin: 8px 0;"><strong>Cliente:</strong> {{ $invoice->client_name }}<br>@if($invoice->client_tax_id)<strong>{{ \App\Enums\TaxIdType::tryFrom((string) $invoice->client_tax_id_type)?->label() ?? 'RNC' }}:</strong> {{ \App\Support\FiscalId::format($invoice->client_tax_id) }}<br>@endif @if($invoice->client_address){{ $invoice->client_address }}<br>@endif</div>
@else
@include('pdf.partials.client-block', ['client' => $invoice->client])
@endif
@if($invoice->kind->value === 'historical')
<p class="muted">Documento registrado para control de saldos; el comprobante original fue emitido fuera del sistema.</p>
@else
@include('pdf.partials.lines-table', ['ecf' => $ecf !== null])
@endif
@include('pdf.partials.totals')
@if(! $invoice->ncf)<p class="muted">Sin valor fiscal.</p>@endif
@if($invoice->notes)<p class="muted">{{ $invoice->notes }}</p>@endif
<p class="muted" style="margin-top: 10px;">{{ $copy }}</p>
@if($ecf !== null)@include('pdf.partials.ecf-stamp', ['ecf' => $ecf])@endif
@endsection
