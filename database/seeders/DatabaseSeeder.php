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
        $this->archiveAuditLog();

        $this->call(DemoSeeder::class);
    }

    /**
     * Seeder boş (ya da sıfırlanmış) veritabanında çalışır; önceki zincirin kontrol noktası
     * satırları yeni veriyle eşleşmez ve "audit:verify --log" yanlış alarm verir. Eski log
     * silinmez, yanına zaman damgalı bir adla taşınır.
     */
    private function archiveAuditLog(): void
    {
        $path = config('logging.channels.audit.path');

        if (is_string($path) && is_file($path) && filesize($path) > 0) {
            rename($path, $path.'.'.now()->format('YmdHis').'.bak');
        }
    }
}
