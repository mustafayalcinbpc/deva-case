<?php

return [

    /*
    | K-06: Açıldıktan sonra bu süre içinde ilk adımı başlatılmayan kayıt
    | sistem tarafından "süresi doldu" durumuna alınır.
    */
    'stale_after_minutes' => (int) env('CLEANING_STALE_AFTER_MINUTES', 30),

    /*
    | K-03: Bu süreyi aşan çalışma dilimi raporda "anormal uzun" olarak işaretlenir.
    */
    'slice_anomaly_hours' => (int) env('CLEANING_SLICE_ANOMALY_HOURS', 4),

];
