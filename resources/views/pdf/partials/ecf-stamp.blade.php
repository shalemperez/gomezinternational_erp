<div class="ecf-stamp">
    @if($ecf['qr_png'])<img src="{{ $ecf['qr_png'] }}" class="ecf-qr" alt="Código QR DGII">@endif
    <div class="ecf-stamp-text">
        @if($ecf['security_code'])Código de seguridad: <strong>{{ $ecf['security_code'] }}</strong><br>@endif
        @if($ecf['signed_at'])Fecha de firma digital: {{ $ecf['signed_at'] }}<br>@endif
        @if($ecf['legend'])<span class="ecf-legend">{{ $ecf['legend'] }}</span>@endif
    </div>
</div>
