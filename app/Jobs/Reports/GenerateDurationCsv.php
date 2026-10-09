<?php

namespace App\Jobs\Reports;

use App\Models\ReportExport;
use App\Services\Reports\DurationCsv;
use App\Services\Reports\ReportFilters;

/**
 * Süre ve efor raporunun makine özetini, istek anındaki filtrelerle CSV olarak üretir.
 * Filtreler açık tarihlerle saklanır; iş geç çalışsa da aynı aralık kullanılır.
 */
class GenerateDurationCsv extends ReportExportJob
{
    public function handle(DurationCsv $csv): void
    {
        $this->produce(function (ReportExport $export) use ($csv) {
            $filters = ReportFilters::fromArray($export->parameters['filters'] ?? []);

            return [$csv->fileName($filters), $csv->render($filters)];
        });
    }
}
