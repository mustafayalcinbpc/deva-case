<?php

use App\Http\Controllers\Admin\DefinitionChangeController;
use Illuminate\Support\Facades\Route;

// Grup ve ön ek routes/web.php'de verilir (docs/plan-yonetim-rapor-tasarim.md).
// Tanım değişiklik günlüğü (R-49): yalnızca okunur; satırlar model olaylarından yazılır.

Route::get('/definition-changes', [DefinitionChangeController::class, 'index'])->name('definition-changes.index');
