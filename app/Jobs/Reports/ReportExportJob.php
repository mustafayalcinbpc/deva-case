<?php

namespace App\Jobs\Reports;

use App\Models\ReportExport;
use App\Notifications\Reports\ReportExportFailed;
use App\Notifications\Reports\ReportExportReady;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Kuyrukta rapor dosyası üretmenin ortak akışı: satırı "hazırlanıyor" yapar, dosyayı özel
 * "local" diske yazar, satırı tamamlar ve isteyene veritabanı bildirimi gönderir. Son deneme
 * de başarısız olursa satır "başarısız" olur ve isteyene hata bildirimi gider.
 *
 * İş, isteği kaydeden transaction commit edildikten sonra kuyruğa gider (ShouldQueueAfterCommit).
 */
abstract class ReportExportJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public ReportExport $export) {}

    /**
     * @param  Closure(ReportExport): array{0: string, 1: string}  $generate  [dosya adı, içerik]
     */
    protected function produce(Closure $generate): void
    {
        $export = $this->export->refresh();

        // Aynı iş ikinci kez teslim edilirse dosya yeniden üretilmez.
        if ($export->status === ReportExport::STATUS_COMPLETED) {
            return;
        }

        $export->update(['status' => ReportExport::STATUS_PROCESSING, 'started_at' => now()]);

        [$fileName, $contents] = $generate($export);
        $path = ReportExport::DIRECTORY."/{$export->id}/{$fileName}";

        Storage::disk('local')->put($path, $contents);

        $export->update([
            'status' => ReportExport::STATUS_COMPLETED,
            'file_path' => $path,
            'file_name' => $fileName,
            'error' => null,
            'completed_at' => now(),
        ]);

        $export->user->notify(new ReportExportReady($export));
    }

    public function failed(?Throwable $exception): void
    {
        $export = $this->export->refresh();

        $export->update([
            'status' => ReportExport::STATUS_FAILED,
            'error' => Str::limit((string) $exception?->getMessage(), 1000),
            'failed_at' => now(),
        ]);

        $export->user->notify(new ReportExportFailed($export));
    }
}
