<?php

/*
| Demo hesapları. DemoSeeder bu kullanıcıları oluşturur, giriş sayfası local ortamda listeler.
| Anahtar (ahmet, yonetici...) seeder'daki senaryolarda kişiyi tanımlar. E-posta adresleri
| unvana göre numaralanır. "active => false" olan hesap, geçmişte kayıt açtıktan sonra pasife alınır.
*/

return [
    'password' => '1234',

    'users' => [
        'ahmet' => ['email' => 'operator1@demo.test', 'name' => 'Ahmet Yılmaz', 'role' => 'operator', 'active' => true],
        'mehmet' => ['email' => 'operator2@demo.test', 'name' => 'Mehmet Kaya', 'role' => 'operator', 'active' => true],
        'ayse' => ['email' => 'operator3@demo.test', 'name' => 'Ayşe Demir', 'role' => 'operator', 'active' => true],
        'eski' => ['email' => 'operator4@demo.test', 'name' => 'Eski Personel', 'role' => 'operator', 'active' => false],
        'yonetici' => ['email' => 'yonetici1@demo.test', 'name' => 'Zeynep Arslan', 'role' => 'manager', 'active' => true],
    ],
];
