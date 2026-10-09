<?php

use App\Http\Controllers\Admin\FacilityController;
use App\Http\Controllers\Admin\LineController;
use App\Http\Controllers\Admin\MachineController;
use Illuminate\Support\Facades\Route;

// Grup ve ön ek routes/web.php'de verilir (docs/plan-yonetim-rapor-tasarim.md).
// Tesis, hat ve makine silinmez: geçmiş kayıtlar onlara bağlıdır (K-16, K-17).

Route::resource('facilities', FacilityController::class)->only(['index', 'create', 'store', 'edit', 'update']);

// Hat bir tesisin içinde açılır; açıldıktan sonra kendi adresiyle düzenlenir.
Route::get('/facilities/{facility}/lines/create', [LineController::class, 'create'])->name('lines.create');
Route::post('/facilities/{facility}/lines', [LineController::class, 'store'])->name('lines.store');
Route::get('/lines/{line}/edit', [LineController::class, 'edit'])->name('lines.edit');
Route::match(['put', 'patch'], '/lines/{line}', [LineController::class, 'update'])->name('lines.update');

Route::resource('machines', MachineController::class)->except(['destroy']);
Route::post('/machines/{machine}/retire', [MachineController::class, 'retire'])->name('machines.retire');
Route::post('/machines/{machine}/reinstate', [MachineController::class, 'reinstate'])->name('machines.reinstate');
