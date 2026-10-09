<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// K-06: başlamamış eski kayıtları "süresi doldu" durumuna alır.
Schedule::command('cleanings:expire-stale')->everyMinute()->withoutOverlapping();

// R-46: olay zincirlerinin dış çapası. Yeni olay varsa kontrol noktası oluşturulur ve
// storage/logs/audit-checkpoints.log dosyasına da yazılır (bu dosya sunucu dışına taşınmalıdır).
Schedule::command('audit:checkpoint')->hourly()->withoutOverlapping();

// R-46: zincirler ve kontrol noktaları her gece günlükle karşılaştırılarak doğrulanır. Sonuç
// uygulama log'una yazılır (sorun varsa error seviyesinde); komut sorun bulunca hata koduyla çıkar.
Schedule::command('audit:verify --log')->dailyAt('03:30')->withoutOverlapping();
