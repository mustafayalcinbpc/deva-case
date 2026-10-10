<?php

/*
| Sidebar menüsü: tek öğeler ve açılır gruplar.
| Öğe:  label, icon (bootstrap-icons sınıfı), route (route adı),
|       active (isteğe bağlı; aktif sayılacak route deseni ya da desen listesi, ör. ['cleanings.index', 'cleanings.show']),
|       roles (isteğe bağlı; ör. ['manager']; verilmezse herkes görür).
| Grup: ['group' => 'Raporlar', 'icon' => ..., 'roles' => [...], 'items' => [öğeler]]. Grubun rolleri
|       öğelerine de uygulanır; görünen öğesi olmayan grup gizlenir. Etkin öğeyi içeren grup açık gelir.
| Route'u tanımlı olmayan öğe gösterilmez (bölüm henüz eklenmemiş olabilir).
*/

return [
    ['label' => 'Gösterge Paneli', 'icon' => 'bi-speedometer2', 'route' => 'dashboard'],

    // Sahadaki iş: herkes.
    ['group' => 'Temizlik', 'icon' => 'bi-droplet-half', 'items' => [
        ['label' => 'Temizlik Kayıtları', 'icon' => 'bi-list-check', 'route' => 'cleanings.index', 'active' => ['cleanings.index', 'cleanings.show']],
        ['label' => 'Yeni Kayıt', 'icon' => 'bi-plus-circle', 'route' => 'cleanings.create'],
    ]],

    // Ne zaman temizlenecek: planlar ve onları tetikleyen üretim iş emirleri.
    ['group' => 'Planlama', 'icon' => 'bi-calendar-week', 'roles' => ['manager'], 'items' => [
        ['label' => 'Temizlik Planları', 'icon' => 'bi-calendar-check', 'route' => 'admin.cleaning-plans.index', 'active' => 'admin.cleaning-plans.*'],
        ['label' => 'Üretim İş Emirleri', 'icon' => 'bi-clipboard-data', 'route' => 'admin.work-orders.index', 'active' => 'admin.work-orders.*'],
    ]],

    // Neyin, nerede, nasıl temizleneceği: saha, makine, prosedür ve malzeme tanımları.
    ['group' => 'Tanımlar', 'icon' => 'bi-diagram-3', 'roles' => ['manager'], 'items' => [
        ['label' => 'Tesis ve Hatlar', 'icon' => 'bi-building', 'route' => 'admin.facilities.index', 'active' => ['admin.facilities.*', 'admin.lines.*']],
        ['label' => 'Makineler', 'icon' => 'bi-gear-wide-connected', 'route' => 'admin.machines.index', 'active' => 'admin.machines.*'],
        ['label' => 'Prosedürler', 'icon' => 'bi-journal-check', 'route' => 'admin.procedures.index', 'active' => 'admin.procedures.*'],
        ['label' => 'Malzemeler ve Lotlar', 'icon' => 'bi-box-seam', 'route' => 'admin.materials.index', 'active' => 'admin.materials.*'],
    ]],

    ['group' => 'Raporlar', 'icon' => 'bi-bar-chart-line', 'roles' => ['manager'], 'items' => [
        ['label' => 'Süre ve Efor', 'icon' => 'bi-stopwatch', 'route' => 'reports.durations'],
        ['label' => 'Sapmalar', 'icon' => 'bi-exclamation-triangle', 'route' => 'reports.deviations'],
        ['label' => 'Malzeme İzlenebilirliği', 'icon' => 'bi-upc-scan', 'route' => 'reports.materials'],
    ]],

    // Kim erişir ve tanımlarda kim neyi değiştirdi.
    ['group' => 'Yönetim', 'icon' => 'bi-shield-lock', 'roles' => ['manager'], 'items' => [
        ['label' => 'Kullanıcılar', 'icon' => 'bi-people', 'route' => 'admin.users.index', 'active' => 'admin.users.*'],
        ['label' => 'Değişiklik Günlüğü', 'icon' => 'bi-clock-history', 'route' => 'admin.definition-changes.index'],
    ]],
];
