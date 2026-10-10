<?php

namespace App\Enums;

/**
 * Görevin nereden doğduğu (K-21). Yöneticinin elle verdiği görev D ile eklenecek.
 */
enum CleaningTaskSource: string
{
    case Periodic = 'periodic';
    case WorkOrder = 'work_order';

    public function label(): string
    {
        return match ($this) {
            self::Periodic => 'Periyodik plan',
            self::WorkOrder => 'Üretim iş emri tamamlandı',
        };
    }
}
