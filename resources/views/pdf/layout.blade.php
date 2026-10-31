<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $fileName }}</title>
    <style>
        @page { margin: 18mm 15mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 16px; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; vertical-align: top; }
        .lines th { background: #eee; border-bottom: 1px solid #999; text-align: left; }
        .lines td { border-bottom: 1px solid #ddd; }
        .num { text-align: right; white-space: nowrap; }
        .muted { color: #666; }
        .box { border: 1px solid #999; padding: 6px 8px; }
        .totals td { padding: 2px 6px; }
        .totals .grand { font-weight: bold; font-size: 12px; border-top: 1px solid #999; }
        .footer { position: fixed; bottom: 0; left: 0; right: 0; font-size: 8px; color: #666; text-align: center; }
        .ecf-stamp { position: fixed; bottom: 12mm; left: 5mm; width: 120mm; font-size: 8px; }
        .ecf-qr { width: 26mm; height: 26mm; margin: 3mm; float: left; }
        .ecf-stamp-text { padding-top: 4mm; }
        .ecf-legend { font-weight: bold; color: #b00000; display: block; margin-top: 2mm; }
    </style>
</head>
<body>
    @include('pdf.partials.company-header')
    @yield('content')
    <div class="footer">{{ $company['name'] }} — Documento generado por Gomez ERP el {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
