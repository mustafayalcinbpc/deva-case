<?php

namespace App\Enums;

use App\Enums\Concerns\DefinesTransitions;

/**
 * Temizlik görevinin durumu (K-23). Görevden kayıt açılınca "kayıt açıldı"; kayıt tamamlanınca
 * "tamamlandı"; kayıt iptal edilir ya da süresi dolarsa görev yeniden "açık" olur.
 */
enum CleaningTaskStatus: string
{
    use DefinesTransitions;

    case Open = 'open';
    case InRecord = 'in_record';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function transitions(): array
    {
        return match ($this) {
            self::Open => [self::InRecord, self::Cancelled],
            self::InRecord => [self::Open, self::Done],
            self::Done, self::Cancelled => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Açık',
            self::InRecord => 'Kayıt açıldı',
            self::Done => 'Tamamlandı',
            self::Cancelled => 'İptal',
        };
    }

    /**
     * Açık ya da kayda bağlı görev planın tek etkin görevidir (cleaning_tasks.open_plan_id).
     */
    public function isActive(): bool
    {
        return $this === self::Open || $this === self::InRecord;
    }
}
