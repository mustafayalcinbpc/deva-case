<?php

namespace App\Listeners;

use App\Events\WorkOrderCompleted;
use App\Models\WorkOrder;
use App\Services\Planning\CleaningTaskGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * K-20: tamamlanan üretim iş emri, makinesindeki "üretim iş emri tamamlanınca" kurallı planlara
 * görev açtırır (kuyrukta). İş emri yoksa sessizce biter.
 */
class OpenCleaningTasksForCompletedWorkOrder implements ShouldQueue
{
    public function __construct(
        private readonly CleaningTaskGenerator $generator,
    ) {}

    public function handle(WorkOrderCompleted $event): void
    {
        $workOrder = WorkOrder::query()->find($event->workOrderId);

        if ($workOrder !== null) {
            $this->generator->forCompletedWorkOrder($workOrder);
        }
    }
}
