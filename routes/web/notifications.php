<?php

// Grup ve ön ek routes/web.php'de verilir (docs/plan-yonetim-rapor-tasarim.md).

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [NotificationController::class, 'index'])->name('index');
Route::post('/read-all', [NotificationController::class, 'readAll'])->name('read-all');
Route::get('/{notification}/open', [NotificationController::class, 'open'])->whereUuid('notification')->name('open');
