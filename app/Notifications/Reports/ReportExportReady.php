<?php

namespace App\Notifications\Reports;

use App\Models\ReportExport;
use Illuminate\Notifications\Notification;

/**
 * Kuyrukta hazırlanan rapor dosyası indirilebilir. Bildirim kuyruk işinin içinden gönderilir;
 * ayrıca kuyruğa alınmaz. Veri biçimi docs/plan-yonetim-rapor-tasarim.md'deki sözleşmedir.
 */
class ReportExportReady extends Notification
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
            'title' => 'Rapor hazır',
            'message' => $this->export->description().' indirilmeye hazır.',
            // Göreli adres: uygulamanın adresi değişse de bildirimdeki bağlantı çalışır.
            'url' => route('reports.exports.download', $this->export, absolute: false),
            'level' => 'info',
        ];
    }
}
