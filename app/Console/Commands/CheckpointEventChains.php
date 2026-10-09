<?php

namespace App\Console\Commands;

use App\Services\Cleaning\AuditCheckpoints;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Zamanlayıcı her saat çalıştırır (routes/console.php). Kontrol noktası ayrıca audit log
 * kanalına (storage/logs/audit-checkpoints.log) tek satır JSON olarak yazılır; bu dosya
 * sunucu dışına taşınmalıdır.
 */
#[Signature('audit:checkpoint')]
#[Description('Olay zincirlerinin başlarını özetleyen bir kontrol noktası oluşturur ve audit günlüğüne yazar (R-46).')]
final class CheckpointEventChains extends Command
{
    public function handle(AuditCheckpoints $audit): int
    {
        $checkpoint = $audit->create();

        if ($checkpoint === null) {
            $this->components->info('Son kontrol noktasından bu yana yeni olay yok; kontrol noktası oluşturulmadı.');

            return self::SUCCESS;
        }

        $this->components->info("Kontrol noktası #{$checkpoint->sequence} oluşturuldu.");
        $this->components->twoColumnDetail('Kapsanan son olay', "#{$checkpoint->max_event_id}");
        $this->components->twoColumnDetail('Olay / kayıt', "{$checkpoint->event_count} / {$checkpoint->cleaning_count}");
        $this->components->twoColumnDetail('Bu aralıkta ilerleyen kayıt', (string) count($checkpoint->heads));
        $this->components->twoColumnDetail('Özet', $checkpoint->digest);
        $this->components->twoColumnDetail('Günlük', $audit->logPath());

        return self::SUCCESS;
    }
}
