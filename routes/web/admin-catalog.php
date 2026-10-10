<?php

// Grup ve ön ek routes/web.php'de verilir (docs/plan-yonetim-rapor-tasarim.md).
// Malzeme kataloğu ve lotları (K-13, K-14), üretim iş emirleri (K-19) ve kullanıcılar (R-36,
// R-40–R-43). Kayıtlar bunlara bağlı olduğu için hiçbiri silinmez; malzeme, lot ve kullanıcı
// pasife alınır.

use App\Http\Controllers\Admin\CleaningPlanController;
use App\Http\Controllers\Admin\MaterialController;
use App\Http\Controllers\Admin\MaterialLotController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkOrderController;
use Illuminate\Support\Facades\Route;

Route::resource('materials', MaterialController::class)->only(['index', 'create', 'store', 'edit', 'update']);
Route::post('/materials/{material}/deactivate', [MaterialController::class, 'deactivate'])->name('materials.deactivate');
Route::post('/materials/{material}/activate', [MaterialController::class, 'activate'])->name('materials.activate');

// K-14: malzemenin lotları; lot adreste bağlı olduğu malzemeye göre çözülür (başka malzemenin lotu 404).
Route::scopeBindings()->prefix('/materials/{material}/lots')->name('materials.lots.')->group(function () {
    Route::post('/', [MaterialLotController::class, 'store'])->name('store');
    Route::get('/{lot}/edit', [MaterialLotController::class, 'edit'])->name('edit');
    Route::match(['put', 'patch'], '/{lot}', [MaterialLotController::class, 'update'])->name('update');
    Route::post('/{lot}/deactivate', [MaterialLotController::class, 'deactivate'])->name('deactivate');
    Route::post('/{lot}/activate', [MaterialLotController::class, 'activate'])->name('activate');
});

Route::resource('work-orders', WorkOrderController::class)->only(['index', 'create', 'store', 'edit', 'update']);
// Demo: ERP'nin durum bildirimi yerine. Tamamlanma temizlik planlarını tetikler (K-20).
Route::post('/work-orders/{work_order}/start', [WorkOrderController::class, 'start'])->name('work-orders.start');
Route::post('/work-orders/{work_order}/complete', [WorkOrderController::class, 'complete'])->name('work-orders.complete');

// K-20: temizlik planları; plan silinmez, kullanımdan kaldırılır.
Route::resource('cleaning-plans', CleaningPlanController::class)->only(['index', 'create', 'store', 'edit', 'update']);
Route::post('/cleaning-plans/{cleaning_plan}/deactivate', [CleaningPlanController::class, 'deactivate'])->name('cleaning-plans.deactivate');
Route::post('/cleaning-plans/{cleaning_plan}/activate', [CleaningPlanController::class, 'activate'])->name('cleaning-plans.activate');

Route::resource('users', UserController::class)->only(['index', 'create', 'store', 'edit', 'update']);
Route::put('/users/{user}/password', [UserController::class, 'password'])->name('users.password');
Route::post('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
Route::post('/users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
