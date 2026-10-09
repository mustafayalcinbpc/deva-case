<?php

namespace App\Enums;

use App\Enums\Concerns\DefinesTransitions;

/**
 * Temizlik kaydının durumu. Duraklatma temizlik düzeyinde değil adım düzeyindedir (K-03).
 *
 *   CREATED ──ilk adım başlar──▶ IN_PROGRESS ──son faz tamamlanır──▶ COMPLETED
 *      ├─ süre doldu (sistem, K-06) ─▶ EXPIRED
 *      └─ sahibi/yönetici (K-08, K-09) ─▶ CANCELLED ◀─ yönetici (K-08) ─┘
 */
enum CleaningStatus: string
{
    use DefinesTransitions;

    case Created = 'created';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function transitions(): array
    {
        return match ($this) {
            self::Created => [self::InProgress, self::Expired, self::Cancelled],
            self::InProgress => [self::Completed, self::Cancelled],
            self::Completed, self::Expired, self::Cancelled => [],
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Created || $this === self::InProgress;
    }

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Başlamadı',
            self::InProgress => 'Devam ediyor',
            self::Completed => 'Tamamlandı',
            self::Expired => 'Süresi doldu',
            self::Cancelled => 'İptal',
        };
    }
}
