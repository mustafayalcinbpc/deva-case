<?php

// Grup ve ön ek routes/web.php'de verilir (docs/plan-yonetim-rapor-tasarim.md).
// Malzeme kataloğu (K-13), iş emirleri (K-19) ve kullanıcılar (R-36, R-40–R-43). Kayıtlar
// bunlara bağlı olduğu için hiçbiri silinmez; malzeme ve kullanıcı pasife alınır.

use App\Http\Controllers\Admin\MaterialController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::resource('materials', MaterialController::class)->only(['index', 'create', 'store', 'edit', 'update']);
Route::post('/materials/{material}/deactivate', [MaterialController::class, 'deactivate'])->name('materials.deactivate');
Route::post('/materials/{material}/activate', [MaterialController::class, 'activate'])->name('materials.activate');

Route::resource('work-orders', WorkOrderController::class)->only(['index', 'create', 'store', 'edit', 'update']);

Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
Route::put('/users/{user}/password', [UserController::class, 'password'])->name('users.password');
Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
Route::post('/users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
