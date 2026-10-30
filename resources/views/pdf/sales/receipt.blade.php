@extends('pdf.layout')

@section('document-box')
<div class="box">
    <strong style="font-size:14px;">RECIBO DE INGRESO</strong><br>
    <strong>{{ $receipt->number }}</strong><br>
    Fecha: {{ $receipt->receipt_date->format('d/m/Y') }}<br>
    Método: {{ $receipt->payment_method->label() }}<br>
    @if($receipt->financialAccount)Cuenta: {{ $receipt->financialAccount->name }}<br>@endif
    @if($receipt->reference)Referencia: {{ $receipt->reference }}@endif
</div>
@endsection

@section('content')
@include('pdf.partials.client-block', ['client' => $receipt->client])
@if($receipt->status === \App\Enums\ReceiptStatus::Cancelled)
<p style="color: #b00020; font-size: 14px; font-weight: bold; text-align: center;">ANULADO</p>
@endif
<table class="lines" style="margin-top: 8px;">
    <thead>
        <tr>
            <th>NCF</th>
            <th>Factura</th>
            <th>Fecha</th>
            <th class="num">Monto aplicado</th>
        </tr>
    </thead>
    <tbody>
        @forelse($receipt->applications as $application)
        <tr>
            <td>{{ $application->invoice->ncf }}</td>
            <td>{{ $application->invoice->number }}</td>
            <td>{{ $application->invoice->issue_date->format('d/m/Y') }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::money($application->amount, $currency) }}</td>
        </tr>
        @empty
        <tr>
            <td colspan="4" class="muted">Sin facturas aplicadas</td>
        </tr>
        @endforelse
    </tbody>
</table>
<table class="totals" style="margin-top: 8px;">
    <tr>
        <td style="width: 70%;">Monto recibido</td>
        <td class="num">{{ \App\Services\Documents\DocumentPdf::money($receipt->amount_received, $currency) }}</td>
    </tr>
    @if(\App\Support\Money\Decimal::cmp($receipt->retention_itbis, '0') > 0)
    <tr>
        <td>Retención ITBIS</td>
        <td class="num">{{ \App\Services\Documents\DocumentPdf::money($receipt->retention_itbis, $currency) }}</td>
    </tr>
    @endif
    @if(\App\Support\Money\Decimal::cmp($receipt->retention_isr, '0') > 0)
    <tr>
        <td>Retención ISR</td>
        <td class="num">{{ \App\Services\Documents\DocumentPdf::money($receipt->retention_isr, $currency) }}</td>
    </tr>
    @endif
    <tr class="grand">
        <td>Total aplicado</td>
        <td class="num">{{ \App\Services\Documents\DocumentPdf::money($receipt->applied_total, $currency) }}</td>
    </tr>
    @if(\App\Support\Money\Decimal::cmp($receipt->unapplied_amount, '0') > 0)
    <tr>
        <td>Saldo a favor</td>
        <td class="num">{{ \App\Services\Documents\DocumentPdf::money($receipt->unapplied_amount, $currency) }}</td>
    </tr>
    @endif
</table>
@if($receipt->notes)<p class="muted">{{ $receipt->notes }}</p>@endif
<p style="margin-top: 40px; width: 240px; border-top: 1px solid #999; padding-top: 4px;">Recibido por</p>
@endsection
