<?php

namespace App\Services\Planning;

use App\Enums\WorkOrderStatus;
use App\Events\WorkOrderCompleted;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Üretim iş emrinin durum geçişleri (K-19). Gerçekte ERP bildirir; demoda yönetici ekrandan
 * yapar. Tamamlanma WorkOrderCompleted olayını yayar; temizlik planlarının tetiği budur (K-20).
 * Geçişler satır kilidiyle yapılır: aynı iş emri iki kez tamamlanmaz, olay bir kez yayılır.
 */
class WorkOrderLifecycle
{
    public function start(WorkOrder $workOrder): WorkOrder
    {
        return $this->transition($workOrder, WorkOrderStatus::InProduction);
    }

    public function complete(WorkOrder $workOrder): WorkOrder
    {
        return $this->transition($workOrder, WorkOrderStatus::Completed);
    }

    private function transition(WorkOrder $workOrder, WorkOrderStatus $target): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $target) {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

            if (! $locked->status->canTransitionTo($target)) {
                throw ValidationException::withMessages([
                    'status' => "{$locked->code} {$locked->status->label()} durumunda; {$target->label()} durumuna geçirilemez.",
                ]);
            }

            $locked->transitionTo($target);

            if ($target === WorkOrderStatus::Completed) {
                $locked->completed_at = now();
            }

            $locked->save();

            if ($target === WorkOrderStatus::Completed) {
                WorkOrderCompleted::dispatch($locked->id);
            }

            return $workOrder->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
