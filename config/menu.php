<?php

/*
| Sidebar menüsü. Her öğe:
|   label, icon (bootstrap-icons sınıfı), route (route adı),
|   active (isteğe bağlı; aktif sayılacak route deseni ya da desen listesi, ör. ['cleanings.index', 'cleanings.show']),
|   roles (isteğe bağlı; ör. ['manager']; verilmezse herkes görür).
| Başlık: ['header' => 'Yönetim', 'roles' => [...]]. Altında görünen öğe yoksa gizlenir.
| Route'u tanımlı olmayan öğe gösterilmez (bölüm henüz eklenmemiş olabilir).
*/

return [
    ['label' => 'Gösterge Paneli', 'icon' => 'bi-speedometer2', 'route' => 'dashboard'],
    ['label' => 'Temizlik Kayıtları', 'icon' => 'bi-list-check', 'route' => 'cleanings.index', 'active' => ['cleanings.index', 'cleanings.show']],
    ['label' => 'Yeni Kayıt', 'icon' => 'bi-plus-circle', 'route' => 'cleanings.create'],

    ['header' => 'Yönetim', 'roles' => ['manager']],
    ['label' => 'Tesis ve Hatlar', 'icon' => 'bi-building', 'route' => 'admin.facilities.index', 'active' => ['admin.facilities.*', 'admin.lines.*'], 'roles' => ['manager']],
    ['label' => 'Makineler', 'icon' => 'bi-gear-wide-connected', 'route' => 'admin.machines.index', 'active' => 'admin.machines.*', 'roles' => ['manager']],
    ['label' => 'Prosedürler', 'icon' => 'bi-journal-check', 'route' => 'admin.procedures.index', 'active' => 'admin.procedures.*', 'roles' => ['manager']],
    ['label' => 'Malzemeler', 'icon' => 'bi-droplet', 'route' => 'admin.materials.index', 'active' => 'admin.materials.*', 'roles' => ['manager']],
    ['label' => 'İş Emirleri', 'icon' => 'bi-clipboard-data', 'route' => 'admin.work-orders.index', 'active' => 'admin.work-orders.*', 'roles' => ['manager']],
    ['label' => 'Kullanıcılar', 'icon' => 'bi-people', 'route' => 'admin.users.index', 'active' => 'admin.users.*', 'roles' => ['manager']],
    ['label' => 'Değişiklik Günlüğü', 'icon' => 'bi-clock-history', 'route' => 'admin.definition-changes.index', 'roles' => ['manager']],

    ['header' => 'Raporlar', 'roles' => ['manager']],
    ['label' => 'Süre ve Efor', 'icon' => 'bi-bar-chart-line', 'route' => 'reports.durations', 'roles' => ['manager']],
    ['label' => 'Sapmalar', 'icon' => 'bi-exclamation-triangle', 'route' => 'reports.deviations', 'roles' => ['manager']],
    ['label' => 'Malzeme İzlenebilirliği', 'icon' => 'bi-upc-scan', 'route' => 'reports.materials', 'roles' => ['manager']],
];
