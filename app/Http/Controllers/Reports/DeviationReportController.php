<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\DeviationReport;
use App\Services\Reports\ReportFilterOptions;
use App\Services\Reports\ReportFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Sapmalar: minimum süresinin altında kapanan fazlar (K-01) ve anormal uzun çalışma
 * dilimleri (K-03). İki tablo ayrı sayfalanır.
 */
class DeviationReportController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly DeviationReport $report,
        private readonly ReportFilterOptions $options,
    ) {}

    public function index(Request $request): View
    {
        $filters = ReportFilters::fromRequest($request);

        return view('reports.deviations', [
            'filters' => $filters,
            'options' => $this->options->all(),
            'phases' => $this->report->belowMinimum($filters, self::PER_PAGE, 'phases_page'),
            'slices' => $this->report->anomalousSlices($filters, self::PER_PAGE, 'slices_page'),
            'thresholdHours' => $this->report->thresholdHours(),
        ]);
    }
}
