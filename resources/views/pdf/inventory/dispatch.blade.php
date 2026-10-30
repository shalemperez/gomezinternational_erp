@extends('pdf.layout')

@section('document-box')
@include('pdf.partials.fiscal-box', ['title' => 'Conduce', 'ncf' => $dispatch->number, 'ncfLabel' => 'Conduce', 'expiresOn' => null, 'modifiedNcf' => null, 'number' => null, 'issueDate' => $dispatch->dispatch_date->format('d/m/Y'), 'dueDate' => null, 'extra' => ['Factura' => $dispatch->invoice->number, 'NCF factura' => $dispatch->invoice->ncf, 'Chofer' => $dispatch->driver_name, 'Vehículo' => $dispatch->vehicle]])
@endsection

@section('content')
<div class="box" style="margin: 8px 0;"><strong>Cliente:</strong> {{ $dispatch->invoice->client_name }}<br>@if($dispatch->invoice->client_address){{ $dispatch->invoice->client_address }}<br>@endif</div>
<table class="lines">
    <thead><tr><th>Producto</th><th>Lote</th><th>Vencimiento</th><th class="num">Cantidad</th></tr></thead>
    <tbody>
    @foreach($lines as $line)
        <tr>
            <td>{{ $line['product'] }}</td>
            <td>{{ $line['lot'] }}</td>
            <td>{{ $line['expires_on'] }}</td>
            <td class="num">{{ \App\Services\Documents\DocumentPdf::number($line['quantity'], 4) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@if($dispatch->notes)<p class="muted">{{ $dispatch->notes }}</p>@endif
<table style="margin-top: 60px;">
    <tr>
        <td style="width: 50%; border-top: 1px solid #111;">Entregado por</td>
        <td style="width: 50%; border-top: 1px solid #111;">Recibido por</td>
    </tr>
</table>
<p class="muted" style="margin-top: 10px;">{{ $copy }}</p>
@endsection
