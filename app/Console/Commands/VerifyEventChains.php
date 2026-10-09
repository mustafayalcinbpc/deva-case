<?php

namespace App\Console\Commands;

use App\Services\Cleaning\AuditCheckpoints;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Zamanlayıcı her gece --log ile çalıştırır (routes/console.php); sonuç uygulama log'una yazılır.
 * Sunucu dışındaki kopyayla karşılaştırmak için: php artisan audit:verify --log-path=/yol/kopya.log
 */
#[Signature('audit:verify
    {--log : Kontrol noktalarını audit günlüğüyle (storage/logs/audit-checkpoints.log) de karşılaştırır}
    {--log-path= : Karşılaştırılacak günlük dosyası, ör. sunucu dışındaki kopya (--log varsayılır)}')]
#[Description('Olay zincirlerini ve kontrol noktalarını doğrular; değişiklik bulunursa hata koduyla çıkar (R-46).')]
final class VerifyEventChains extends Command
{
    public function handle(AuditCheckpoints $audit): int
    {
        $logPath = $this->option('log-path') ?: ($this->option('log') ? $audit->logPath() : null);
        $report = $audit->verify($logPath);
        $last = $report->lastCheckpoint;

        $this->components->twoColumnDetail('<fg=gray>Temizlik kaydı</>', (string) $report->cleanings);
        $this->components->twoColumnDetail('<fg=gray>Olay</>', (string) $report->events);
        $this->components->twoColumnDetail('<fg=gray>Zinciri bozuk kayıt</>', (string) $report->brokenChains);
        $this->components->twoColumnDetail('<fg=gray>Kontrol noktası</>', $last === null
            ? 'yok'
            : "{$report->checkpoints} (son: #{$last->sequence}, {$last->created_at->utc()->format('Y-m-d H:i:s')} UTC, olay #{$last->max_event_id} dahil)");
        $this->components->twoColumnDetail('<fg=gray>Son kontrol noktasından sonraki olay</>', (string) $report->uncoveredEvents);

        if ($report->logPath !== null) {
            $this->components->twoColumnDetail('<fg=gray>Günlük</>', "{$report->logEntries} satır ({$report->logPath})");
        }

        if ($report->passed()) {
            if ($last === null && $report->events > 0) {
                $this->components->warn('Henüz kontrol noktası yok; olaylar yalnızca kayıt zinciriyle korunuyor (php artisan audit:checkpoint).');
            }

            $this->components->info('Doğrulama başarılı: olay zincirleri ve kontrol noktaları tutarlı.');
            Log::info('audit:verify başarılı', $report->summary());

            return self::SUCCESS;
        }

        $this->components->error('Doğrulama başarısız: '.count($report->problems).' sorun bulundu.');
        $this->components->bulletList($report->problems);
        Log::error('audit:verify başarısız', $report->summary() + ['problems' => $report->problems]);

        return self::FAILURE;
    }
}
