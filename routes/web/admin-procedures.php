<?php

use App\Http\Controllers\Admin\ProcedureController;
use App\Http\Controllers\Admin\ProcedurePhaseController;
use App\Http\Controllers\Admin\ProcedurePublicationController;
use App\Http\Controllers\Admin\ProcedureStepController;
use App\Http\Controllers\Admin\ProcedureVersionController;
use App\Http\Controllers\Admin\ProcedureVersionMaterialController;
use Illuminate\Support\Facades\Route;

// Grup ve ön ek routes/web.php'de verilir (docs/plan-yonetim-rapor-tasarim.md).
// Prosedür, versiyon, faz ve adım tanımları (R-01–R-06, K-15). Prosedür silinmez; yayımlanmış
// versiyon değişmez. Taslak düzenlenir, yayımlanır ya da silinir. Versiyon, faz ve adım
// adreste bağlı oldukları üst kayda göre çözülür (başka prosedürün versiyonu 404).

Route::resource('procedures', ProcedureController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update']);

Route::scopeBindings()->prefix('/procedures/{procedure}/versions')->name('procedures.')->group(function () {
    Route::post('/', [ProcedureVersionController::class, 'store'])->name('versions.store');
    Route::get('/{version}', [ProcedureVersionController::class, 'show'])->name('versions.show');
    Route::delete('/{version}', [ProcedureVersionController::class, 'destroy'])->name('versions.destroy');
    Route::post('/{version}/publish', [ProcedurePublicationController::class, 'store'])->name('versions.publish');

    // K-13: beklenen malzemeler; {material} versiyonun listesindeki satırdır (ProcedureVersionMaterial).
    Route::post('/{version}/materials', [ProcedureVersionMaterialController::class, 'store'])->name('materials.store');
    Route::match(['put', 'patch'], '/{version}/materials/{material}', [ProcedureVersionMaterialController::class, 'update'])->name('materials.update');
    Route::delete('/{version}/materials/{material}', [ProcedureVersionMaterialController::class, 'destroy'])->name('materials.destroy');
    Route::post('/{version}/materials/{material}/move/{direction}', [ProcedureVersionMaterialController::class, 'move'])
        ->whereIn('direction', ['up', 'down'])
        ->name('materials.move');

    Route::post('/{version}/phases', [ProcedurePhaseController::class, 'store'])->name('phases.store');
    Route::get('/{version}/phases/{phase}/edit', [ProcedurePhaseController::class, 'edit'])->name('phases.edit');
    Route::match(['put', 'patch'], '/{version}/phases/{phase}', [ProcedurePhaseController::class, 'update'])->name('phases.update');
    Route::delete('/{version}/phases/{phase}', [ProcedurePhaseController::class, 'destroy'])->name('phases.destroy');
    Route::post('/{version}/phases/{phase}/move/{direction}', [ProcedurePhaseController::class, 'move'])
        ->whereIn('direction', ['up', 'down'])
        ->name('phases.move');

    Route::get('/{version}/phases/{phase}/steps/create', [ProcedureStepController::class, 'create'])->name('steps.create');
    Route::post('/{version}/phases/{phase}/steps', [ProcedureStepController::class, 'store'])->name('steps.store');
    Route::get('/{version}/phases/{phase}/steps/{step}/edit', [ProcedureStepController::class, 'edit'])->name('steps.edit');
    Route::match(['put', 'patch'], '/{version}/phases/{phase}/steps/{step}', [ProcedureStepController::class, 'update'])->name('steps.update');
    Route::delete('/{version}/phases/{phase}/steps/{step}', [ProcedureStepController::class, 'destroy'])->name('steps.destroy');
    Route::post('/{version}/phases/{phase}/steps/{step}/move/{direction}', [ProcedureStepController::class, 'move'])
        ->whereIn('direction', ['up', 'down'])
        ->name('steps.move');
});
