<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// K-06: başlamamış eski kayıtları "süresi doldu" durumuna alır.
Schedule::command('cleanings:expire-stale')->everyMinute()->withoutOverlapping();
