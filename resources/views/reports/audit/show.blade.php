<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Denetim raporu {{ $cleaning->record_no }} · {{ config('app.name') }}</title>
    {{--
        Yazdırılabilir denetim raporu (R-45–R-49). Uygulama kabuğu olmadan, kendi CSS'iyle bir
        belge olarak gösterilir; tarayıcının "Yazdır / PDF olarak kaydet" çıktısı aynı sayfadır.
        Araç çubuğu yazdırmada gizlenir.
    --}}
    <style>
        @page { size: A4; margin: 12mm; }
        body { margin: 0; background: #e9ecef; font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; font-size: 10pt; }
        .audit-toolbar { position: sticky; top: 0; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 10px 16px; background: #fff; border-bottom: 1px solid #ced4da; }
        .audit-toolbar form { margin: 0; }
        .audit-toolbar__spacer { flex: 1 1 auto; }
        .audit-button { display: inline-block; padding: 6px 12px; border: 1px solid #adb5bd; border-radius: 6px; background: #fff; color: #1b1f24; font: inherit; text-decoration: none; cursor: pointer; }
        .audit-button--primary { border-color: #1f4fa3; background: #1f4fa3; color: #fff; }
        .audit-flash { max-width: 210mm; margin: 12px auto 0; padding: 8px 12px; border: 1px solid #1e7b34; border-radius: 6px; background: #edf7ef; box-sizing: border-box; }
        .audit-page { max-width: 210mm; margin: 16px auto; padding: 14mm 12mm; background: #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, 0.15); box-sizing: border-box; }
        @media (max-width: 600px) { .audit-page { padding: 16px; } }
        @media print {
            body { background: #fff; }
            .audit-toolbar, .audit-flash { display: none; }
            .audit-page { max-width: none; margin: 0; padding: 0; box-shadow: none; }
        }
        @include('reports.audit.styles')
    </style>
</head>
<body>
    <nav class="audit-toolbar" aria-label="Rapor işlemleri">
        <a href="{{ route('cleanings.show', $cleaning) }}" class="audit-button">← Kayda dön</a>
        <a href="{{ route('reports.durations') }}" class="audit-button">Raporlar</a>
        <span class="audit-toolbar__spacer"></span>
        <button type="button" class="audit-button" data-audit-print>Yazdır</button>
        <form method="POST" action="{{ route('reports.audit.pdf', $cleaning) }}">
            @csrf
            <button type="submit" class="audit-button audit-button--primary">PDF olarak hazırla</button>
        </form>
    </nav>

    @if (session('status'))
        <p class="audit-flash" role="status">{{ session('status') }}</p>
    @endif

    <main class="audit-page">
        @include('reports.audit.document')
    </main>

    <script>
        document.querySelector('[data-audit-print]').addEventListener('click', () => window.print());
    </script>
</body>
</html>
