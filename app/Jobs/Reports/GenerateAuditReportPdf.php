<?php

namespace App\Jobs\Reports;

use App\Models\Cleaning;
use App\Models\ReportExport;
use App\Services\Reports\AuditReport;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Kaydın denetim raporunu (R-45–R-49) dompdf ile PDF'e çevirir. Sayfa, tarayıcıdaki
 * yazdırılabilir raporla aynı veriyi ve aynı belge gövdesini kullanır.
 */
class GenerateAuditReportPdf extends ReportExportJob
{
    public function handle(AuditReport $report): void
    {
        $this->produce(function (ReportExport $export) use ($report) {
            $cleaning = Cleaning::query()->findOrFail($export->parameters['cleaning_id']);
            $data = $report->build($cleaning, $export->user);

            $pdf = Pdf::loadView('reports.audit.pdf', $data)
                ->setPaper('a4')
                // Yalnızca kullanılan karakterler gömülür; dosya küçük kalır.
                ->setOption('isFontSubsettingEnabled', true)
                ->addInfo([
                    'Title' => "Denetim raporu {$cleaning->record_no}",
                    'Creator' => config('app.name'),
                ]);

            $pdf->render();

            // Her sayfanın altına sayfa numarası (Türkçe karakterler için DejaVu Sans).
            $dompdf = $pdf->getDomPDF();
            $canvas = $dompdf->getCanvas();
            $canvas->page_text(
                $canvas->get_width() - 110,
                $canvas->get_height() - 28,
                'Sayfa {PAGE_NUM} / {PAGE_COUNT}',
                $dompdf->getFontMetrics()->getFont('DejaVu Sans'),
                7,
                [0.35, 0.35, 0.35],
            );

            $fileName = 'denetim-raporu_'.preg_replace('/[^A-Za-z0-9-]+/', '-', $cleaning->record_no).'.pdf';

            return [$fileName, $pdf->output()];
        });
    }
}
