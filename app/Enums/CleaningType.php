<?php

namespace App\Enums;

enum CleaningType: string
{
    case Planned = 'planned';
    case Unplanned = 'unplanned';

    /**
     * Saha defterine yalnızca planlı temizlikler işlenir (R-18, R-19).
     */
    public function hasFieldReference(): bool
    {
        return $this === self::Planned;
    }

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planlı temizlik',
            self::Unplanned => 'Plansız müdahale',
        };
    }
}
