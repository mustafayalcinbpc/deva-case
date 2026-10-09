<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Model olayları kapatılmaz (WithoutModelEvents yok): değiştirilemezlik korumaları demo
     * verisi oluşturulurken de çalışır.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
