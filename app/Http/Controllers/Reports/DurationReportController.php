<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Jobs\Reports\GenerateDurationCsv;
use App\Models\Machine;
use App\Models\ReportExport;
use App\Services\Reports\DurationReport;
use App\Services\Reports\ReportFilterOptions;
use App\Services\Reports\ReportFilters;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Süre ve efor raporu (R-26, R-28, K-02): makine başına tamamlanan kayıt sayısı, net / brüt
 * süre ve insan eforu. Makine seçilince fazlar (minimum süre karşılaştırması, K-01) ve o
 * makinenin kayıtları da gösterilir.
 */
class DurationReportController extends Controller
{
    use QueuesReportExports;

    private const PER_PAGE = 25;

    public function __construct(
        private readonly DurationReport $report,
        private readonly ReportFilterOptions $options,
    ) {}

    public function index(Request $request): View
    {
        $filters = ReportFilters::fromRequest($request);
        $machine = $filters->machineId === null
            ? null
            : Machine::query()->with('line.facility')->find($filters->machineId);

        return view('reports.durations', [
            'filters' => $filters,
            'options' => $this->options->all(),
            'machines' => $this->report->machines($filters),
            'machine' => $machine,
            'phases' => $machine ? $this->report->phases($filters) : collect(),
            'cleanings' => $machine ? $this->report->cleanings($filters, self::PER_PAGE) : null,
        ]);
    }

    /**
     * Ekrandaki filtrelerle CSV dışa aktarımını kuyruğa alır.
     */
    public function export(Request $request): RedirectResponse
    {
        $filters = ReportFilters::fromArray($request->input());

        $this->queueExport($request->user(), ReportExport::TYPE_DURATIONS_CSV, [
            'filters' => $filters->toArray(),
            'period' => $filters->periodLabel(),
        ], GenerateDurationCsv::class);

        return redirect()
            ->route('reports.durations', $filters->toArray())
            ->with('status', self::QUEUED_MESSAGE);
    }
}
