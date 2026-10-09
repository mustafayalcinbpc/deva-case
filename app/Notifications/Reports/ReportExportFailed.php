<?php

namespace App\Notifications\Reports;

use App\Models\ReportExport;
use Illuminate\Notifications\Notification;

/**
 * Rapor dosyası bütün denemelere rağmen üretilemedi. Bağlantı, raporun yeniden istenebileceği
 * sayfaya gider.
 */
class ReportExportFailed extends Notification
{
    public function __construct(public ReportExport $export) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, message: string, url: ?string, level: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => 'Rapor hazırlanamadı',
            'message' => $this->export->description().' hazırlanırken bir hata oluştu. Lütfen yeniden deneyin.',
            'url' => $this->export->sourceUrl(),
            'level' => 'danger',
        ];
    }
}
