<?php

// Grup ve ön ek routes/web.php'de verilir (docs/plan-yonetim-rapor-tasarim.md).
// Raporlar yalnızca yöneticiye açıktır (R-43, K-11): auth + can:view-reports, ön ek "reports",
// ad "reports.".

use App\Http\Controllers\Reports\AuditReportController;
use App\Http\Controllers\Reports\DeviationReportController;
use App\Http\Controllers\Reports\DurationReportController;
use App\Http\Controllers\Reports\MaterialTraceController;
use App\Http\Controllers\Reports\ReportExportController;
use Illuminate\Support\Facades\Route;

Route::get('/durations', [DurationReportController::class, 'index'])->name('durations');
Route::post('/durations/csv', [DurationReportController::class, 'export'])->name('durations.csv');

Route::get('/deviations', [DeviationReportController::class, 'index'])->name('deviations');

Route::get('/materials', [MaterialTraceController::class, 'index'])->name('materials');

Route::get('/cleanings/{cleaning}/audit', [AuditReportController::class, 'show'])->name('audit');
Route::post('/cleanings/{cleaning}/audit/pdf', [AuditReportController::class, 'export'])->name('audit.pdf');

Route::get('/exports/{export}/download', [ReportExportController::class, 'download'])->name('exports.download');
