<div class="box">
    <strong style="font-size: 13px;">{{ $title }}</strong><br>
    @if($ncf)<strong>{{ $ncfLabel ?? 'NCF' }}: {{ $ncf }}</strong><br>@else<strong style="font-size: 14px;">BORRADOR</strong><br>@endif
    @if($expiresOn)Válida hasta: {{ $expiresOn }}<br>@endif
    @if($modifiedNcf){{ ($ncfLabel ?? 'NCF') === 'e-NCF' ? 'e-NCF modificado' : 'Modifica NCF' }}: {{ $modifiedNcf }}<br>@endif
    @if($modificationLabel ?? null)Código de modificación: {{ $modificationLabel }}<br>@endif
    @if($number)No. interno: {{ $number }}<br>@endif
    Fecha de emisión: {{ $issueDate }}<br>
    @if($dueDate)Vencimiento: {{ $dueDate }}<br>@endif
    @foreach($extra as $label => $value){{ $label }}: {{ $value }}<br>@endforeach
</div>
