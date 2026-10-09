<?php

namespace App\Enums;

use App\Enums\Concerns\DefinesTransitions;

enum PhaseStatus: string
{
    use DefinesTransitions;

    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';

    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::InProgress],
            self::InProgress => [self::Completed],
            self::Completed => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Bekliyor',
            self::InProgress => 'Devam ediyor',
            self::Completed => 'Tamamlandı',
        };
    }
}
