<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CleaningCancellationController;
use App\Http\Controllers\CleaningController;
use App\Http\Controllers\CleaningDetailController;
use App\Http\Controllers\CleaningMaterialController;
use App\Http\Controllers\CleaningStepController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/cleanings', [CleaningController::class, 'index'])->name('cleanings.index');
    Route::get('/cleanings/create', [CleaningController::class, 'create'])->name('cleanings.create');
    Route::post('/cleanings', [CleaningController::class, 'store'])->name('cleanings.store');
    Route::get('/cleanings/{cleaning}', [CleaningDetailController::class, 'show'])->name('cleanings.show');

    // Aksiyonlar; adım ve malzeme kayda bağlıdır (başka kaydın adımı 404).
    Route::scopeBindings()->prefix('/cleanings/{cleaning}')->name('cleanings.')->group(function () {
        Route::post('/steps/{step}/start', [CleaningStepController::class, 'start'])->name('steps.start');
        Route::post('/steps/{step}/pause', [CleaningStepController::class, 'pause'])->name('steps.pause');
        Route::post('/steps/{step}/resume', [CleaningStepController::class, 'resume'])->name('steps.resume');
        Route::post('/steps/{step}/complete', [CleaningStepController::class, 'complete'])->name('steps.complete');
        Route::put('/steps/{step}/workers', [CleaningStepController::class, 'workers'])->name('steps.workers');
        Route::post('/materials', [CleaningMaterialController::class, 'store'])->name('materials.store');
        Route::post('/materials/{material}/void', [CleaningMaterialController::class, 'void'])->name('materials.void');
        Route::post('/cancel', [CleaningCancellationController::class, 'store'])->name('cancel');
    });
});
