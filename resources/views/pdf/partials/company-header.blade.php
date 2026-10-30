<table style="margin-bottom: 10px;">
    <tr>
        <td style="width: 60%;">
            @if($company['logo_path_absolute'])<img src="{{ $company['logo_path_absolute'] }}" style="max-height: 50px; margin-bottom: 4px;"><br>@endif
            <strong style="font-size: 13px;">{{ $company['legal_name'] ?: $company['name'] }}</strong><br>
            @if($company['legal_name'] && $company['name'] && $company['legal_name'] !== $company['name'])<span>{{ $company['name'] }}</span><br>@endif
            @if($company['rnc_formatted'])RNC: {{ $company['rnc_formatted'] }}<br>@endif
            @if($company['address']){{ $company['address'] }}<br>@endif
            @if($company['phone'])Tel.: {{ $company['phone'] }}@endif @if($company['email']) · {{ $company['email'] }}@endif
        </td>
        <td style="width: 40%; text-align: right;">@yield('document-box')</td>
    </tr>
</table>
