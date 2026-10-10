<?php

namespace App\Enums;

use App\Enums\Concerns\DefinesTransitions;

/**
 * Üretim iş emrinin durumu (K-19). Gerçekte ERP'den gelir; demoda yönetici değiştirir.
 * "Tamamlandı"ya geçiş temizlik planlarının tetiğidir (K-20).
 */
enum WorkOrderStatus: string
{
    use DefinesTransitions;

    case Planned = 'planned';
    case InProduction = 'in_production';
    case Completed = 'completed';

    public function transitions(): array
    {
        return match ($this) {
            self::Planned => [self::InProduction, self::Completed],
            self::InProduction => [self::Completed],
            self::Completed => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planlandı',
            self::InProduction => 'Üretimde',
            self::Completed => 'Tamamlandı',
        };
    }
}
