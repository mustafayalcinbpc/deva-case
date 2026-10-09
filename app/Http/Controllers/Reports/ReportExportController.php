<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Hazırlanan rapor dosyasını indirir. Dosya özel "local" diskte durur; yalnızca isteyen kişi
 * ya da başka bir yönetici indirebilir. Hazır olmayan ya da diskte bulunmayan dosya 404.
 */
class ReportExportController extends Controller
{
    private const CONTENT_TYPES = [
        ReportExport::TYPE_AUDIT_PDF => 'application/pdf',
        ReportExport::TYPE_DURATIONS_CSV => 'text/csv; charset=UTF-8',
    ];

    public function download(Request $request, ReportExport $export): StreamedResponse
    {
        abort_unless($export->isDownloadableBy($request->user()), 403);

        $disk = Storage::disk('local');
        abort_unless($export->isCompleted() && $disk->exists($export->file_path), 404);

        return $disk->download($export->file_path, $export->file_name, array_filter([
            'Content-Type' => self::CONTENT_TYPES[$export->type] ?? null,
        ]));
    }
}
