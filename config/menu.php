<?php

/*
| Sidebar menüsü. Her öğe:
|   label, icon (bootstrap-icons sınıfı), route (route adı),
|   active (isteğe bağlı; aktif sayılacak route deseni ya da desen listesi, ör. ['cleanings.index', 'cleanings.show']),
|   roles (isteğe bağlı; ör. ['manager']; verilmezse herkes görür).
| Başlık: ['header' => 'Yönetim', 'roles' => [...]]. Altında görünen öğe yoksa gizlenir.
*/

return [
    ['label' => 'Gösterge Paneli', 'icon' => 'bi-speedometer2', 'route' => 'dashboard'],
    ['label' => 'Temizlik Kayıtları', 'icon' => 'bi-list-check', 'route' => 'cleanings.index', 'active' => ['cleanings.index', 'cleanings.show']],
    ['label' => 'Yeni Kayıt', 'icon' => 'bi-plus-circle', 'route' => 'cleanings.create'],
];
