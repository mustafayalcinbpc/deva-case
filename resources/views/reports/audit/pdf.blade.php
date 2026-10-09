<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Denetim raporu {{ $cleaning->record_no }}</title>
    {{-- dompdf için: Türkçe karakterleri kapsayan gömülü DejaVu yazı tipleri; alt boşluk sayfa numarasına ayrılır. --}}
    <style>
        @page { margin: 14mm 12mm 18mm 12mm; }
        body { margin: 0; font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; }
        @include('reports.audit.styles')
    </style>
</head>
<body>
    @include('reports.audit.document')
</body>
</html>
