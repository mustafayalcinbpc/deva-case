<?php

/*
| Sidebar menüsü. Her öğe:
|   label, icon (bootstrap-icons sınıfı), route (route adı),
|   active (isteğe bağlı; aktif sayılacak route deseni, ör. 'cleanings.*'),
|   roles (isteğe bağlı; ör. ['manager']; verilmezse herkes görür).
| Başlık: ['header' => 'Yönetim', 'roles' => [...]]. Altında görünen öğe yoksa gizlenir.
*/

return [
    ['label' => 'Gösterge Paneli', 'icon' => 'bi-speedometer2', 'route' => 'dashboard'],
];
