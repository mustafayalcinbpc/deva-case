<?php

namespace App\Enums;

use App\Enums\Concerns\DefinesTransitions;

/**
 * Adım durumu. Her RUNNING dönemi bir çalışma dilimidir (K-03, K-04).
 *
 *   PENDING ──▶ RUNNING ⇄ PAUSED
 *                  └──▶ COMPLETED
 */
enum StepStatus: string
{
    use DefinesTransitions;

    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';

    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Running],
            self::Running => [self::Paused, self::Completed],
            self::Paused => [self::Running],
            self::Completed => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Bekliyor',
            self::Running => 'Çalışıyor',
            self::Paused => 'Duraklatıldı',
            self::Completed => 'Tamamlandı',
        };
    }
}
