<?php

namespace App\Enums;

/**
 * Temizlik planının kuralı (K-20): belirli aralıklarla ya da makinedeki üretim iş emri
 * tamamlanınca görev üretilir.
 */
enum CleaningPlanKind: string
{
    case Periodic = 'periodic';
    case WorkOrderCompleted = 'work_order_completed';

    public function label(): string
    {
        return match ($this) {
            self::Periodic => 'Periyodik',
            self::WorkOrderCompleted => 'Üretim iş emri tamamlanınca',
        };
    }

    /**
     * Bu kuralla üretilen görevin kaynağı.
     */
    public function taskSource(): CleaningTaskSource
    {
        return match ($this) {
            self::Periodic => CleaningTaskSource::Periodic,
            self::WorkOrderCompleted => CleaningTaskSource::WorkOrder,
        };
    }
}
