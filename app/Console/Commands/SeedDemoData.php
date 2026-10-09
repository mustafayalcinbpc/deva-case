<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Veritabanı boşsa demo verisini yükler. Container ilk açılışta çalıştırır; dolu veritabanına
 * dokunmaz. Sıfırdan yüklemek için: php artisan migrate:fresh --seed
 */
#[Signature('demo:seed')]
#[Description('Veritabanı boşsa demo verisini yükler (DEMO_SEED=true iken)')]
class SeedDemoData extends Command
{
    public function handle(): int
    {
        if (! config('app.demo_seed')) {
            $this->components->info('Demo verisi kapalı (DEMO_SEED=false); yüklenmedi.');

            return self::SUCCESS;
        }

        if (User::query()->exists()) {
            $this->components->info('Veritabanında kayıt var; demo verisi yüklenmedi.');

            return self::SUCCESS;
        }

        $this->components->info('Veritabanı boş; demo verisi yükleniyor.');

        return $this->call('db:seed', ['--force' => true]);
    }
}
