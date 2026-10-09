<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Jobs\Reports\GenerateAuditReportPdf;
use App\Models\Cleaning;
use App\Models\ReportExport;
use App\Services\Reports\AuditReport;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Kayıt bazında denetim raporu (R-45–R-49): tarayıcıda yazdırılabilir sayfa ve kuyrukta
 * üretilen PDF.
 */
class AuditReportController extends Controller
{
    use QueuesReportExports;

    public function __construct(private readonly AuditReport $report) {}

    public function show(Request $request, Cleaning $cleaning): View
    {
        return view('reports.audit.show', $this->report->build($cleaning, $request->user()));
    }

    public function export(Request $request, Cleaning $cleaning): RedirectResponse
    {
        $this->queueExport($request->user(), ReportExport::TYPE_AUDIT_PDF, [
            'cleaning_id' => $cleaning->id,
            'record_no' => $cleaning->record_no,
        ], GenerateAuditReportPdf::class);

        // İstek kayıt detayından da gelebilir; geldiği sayfaya döner.
        return redirect()
            ->back(fallback: route('reports.audit', $cleaning))
            ->with('status', self::QUEUED_MESSAGE);
    }
}
