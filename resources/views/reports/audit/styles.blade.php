{{-- Denetim raporu gövdesinin CSS'i; yazdırma ve dompdf için kendi içinde (tema katmanına bağlı değil). --}}
.audit { color: #1b1f24; line-height: 1.35; }
.audit p { margin: 0 0 4pt; }
.audit h1 { font-size: 17pt; margin: 0 0 2pt; }
.audit h2 { font-size: 11.5pt; margin: 14pt 0 6pt; padding-bottom: 3pt; border-bottom: 1px solid #8a939e; page-break-after: avoid; }
.audit-kicker { font-size: 8pt; letter-spacing: 0.04em; color: #59636e; }
.audit-record { font-size: 13pt; font-weight: bold; }
.audit-meta, .audit-note { font-size: 8pt; color: #59636e; }
.audit table { width: 100%; border-collapse: collapse; margin: 0 0 6pt; }
.audit th, .audit td { border: 1px solid #c9ced4; padding: 3pt 5pt; text-align: left; vertical-align: top; }
.audit thead th { background: #eef0f3; font-weight: bold; }
.audit tr { page-break-inside: avoid; }
.audit-facts th { width: 26%; background: #f6f7f9; font-weight: bold; }
.audit-facts td.audit-note { width: 46%; }
.audit-phase td { background: #e8edf3; }
.audit-num { text-align: right; white-space: nowrap; }
.audit-deviation { color: #9a4d00; font-weight: bold; }
.audit-hash { font-family: "DejaVu Sans Mono", "SFMono-Regular", Consolas, monospace; font-size: 6.8pt; color: #3d444d; word-wrap: break-word; }
.audit-events th:nth-child(1) { width: 5%; }
.audit-events th:nth-child(2) { width: 12%; }
.audit-events th:nth-child(3) { width: 14%; }
.audit-steps th:nth-child(1) { width: 26%; }
.audit-steps th:nth-child(2) { width: 11%; }
.audit-steps th:nth-child(3), .audit-steps th:nth-child(4) { width: 12%; }
.audit-steps th:nth-child(5) { width: 9%; }
.audit-steps th:nth-child(6) { width: 10%; }
.audit-steps th:nth-child(7) { width: 20%; }
.audit-slices th:nth-child(1) { width: 9%; }
.audit-slices th:nth-child(2), .audit-slices th:nth-child(3) { width: 13%; }
.audit-slices th:nth-child(4) { width: 10%; }
.audit-slices th:nth-child(5) { width: 6%; }
.audit-slices th:nth-child(6) { width: 31%; }
.audit-slices th:nth-child(7) { width: 18%; }
.audit-materials th:nth-child(1) { width: 26%; }
.audit-materials th:nth-child(2) { width: 14%; }
.audit-materials th:nth-child(3) { width: 11%; }
.audit-materials th:nth-child(4) { width: 19%; }
.audit-integrity { border: 1.5pt solid #8a939e; padding: 6pt 8pt; margin: 10pt 0 4pt; }
.audit-integrity--ok { border-color: #1e7b34; background: #edf7ef; }
.audit-integrity--broken { border-color: #b42318; background: #fdeceb; }
.audit-integrity p { margin: 0 0 2pt; }
