<?php

namespace App\Services\Planning;

use App\Enums\CleaningPlanKind;
use App\Enums\UserRole;
use App\Enums\WorkOrderStatus;
use App\Models\CleaningPlan;
use App\Models\CleaningTask;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\Planning\CleaningTaskOverdueNotification;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Temizlik planlarından görev üretimi (K-20, K-21) ve geciken görevlerin bildirimi (K-23).
 *
 * Bir planın aynı anda tek etkin görevi olur (cleaning_tasks.open_plan_id unique). Etkin görevi
 * olan plana yeni görev açılmaz; eşzamanlı iki üretimden biri unique index'e takılır ve "zaten
 * var" sayılır. Kullanımdan kaldırılmış plan ve makine görev üretmez.
 */
class CleaningTaskGenerator
{
    /**
     * Periyodik planlar: etkin görevi yoksa ve son görevden bu yana aralık dolduysa (hiç görev
     * üretilmediyse hemen) görev açılır. Son tarih, aralığın dolduğu andır.
     *
     * @return int açılan görev sayısı
     */
    public function generateDue(CarbonInterface $now): int
    {
        $now = CarbonImmutable::instance($now);
        $opened = 0;

        $plans = $this->usablePlans(CleaningPlanKind::Periodic)
            ->whereNotNull('interval_days')
            ->orderBy('id')
            ->get();

        foreach ($plans as $plan) {
            $dueAt = $plan->last_task_at?->addDays($plan->interval_days) ?? $now;

            if ($dueAt->greaterThan($now)) {
                continue;
            }

            $opened += $this->open($plan, $dueAt, $now) ? 1 : 0;
        }

        return $opened;
    }

    /**
     * "Üretim iş emri tamamlanınca" kurallı planlar: iş emrinin makinesindeki ya da (makineye
     * bağlı değilse) hattındaki makinelerdeki planlar görev açar. Tetikleyen emir tamamlanan;
     * sonraki emir, makinede kullanılabilen en erken planlanmış emirdir. Son tarih tamamlanma anıdır.
     *
     * @return int açılan görev sayısı
     */
    public function forCompletedWorkOrder(WorkOrder $workOrder): int
    {
        if ($workOrder->status !== WorkOrderStatus::Completed || ($workOrder->machine_id === null && $workOrder->line_id === null)) {
            return 0;
        }

        $now = CarbonImmutable::now();
        $plans = $this->usablePlans(CleaningPlanKind::WorkOrderCompleted)
            ->whereHas('machine', fn (Builder $machine) => $workOrder->machine_id !== null
                ? $machine->whereKey($workOrder->machine_id)
                : $machine->where('line_id', $workOrder->line_id))
            ->with('machine')
            ->orderBy('id')
            ->get();

        $opened = 0;

        foreach ($plans as $plan) {
            $next = $this->nextWorkOrder($plan->machine, $workOrder);
            $opened += $this->open($plan, $workOrder->completed_at ?? $now, $now, $workOrder, $next) ? 1 : 0;
        }

        return $opened;
    }

    /**
     * Son tarihi geçen açık görevler bütün aktif yöneticilere bir kez bildirilir. Bildirilmiş
     * görev, bildirim tablosundaki kaydından anlaşılır.
     *
     * @return int bildirilen görev sayısı
     */
    public function notifyOverdue(CarbonInterface $now): int
    {
        $tasks = CleaningTask::query()
            ->open()
            ->where('due_at', '<', $now)
            ->whereNotExists(fn ($query) => $query
                ->from('notifications')
                ->where('type', CleaningTaskOverdueNotification::TYPE)
                ->whereRaw("json_unquote(json_extract(notifications.data, '$.task_id')) = cleaning_tasks.id"))
            ->with('machine.line.facility')
            ->orderBy('due_at')
            ->get();

        $managers = User::query()->where('role', UserRole::Manager)->where('is_active', true)->get();

        if ($tasks->isEmpty() || $managers->isEmpty()) {
            return 0;
        }

        foreach ($tasks as $task) {
            Notification::send($managers, new CleaningTaskOverdueNotification($task));
        }

        return $tasks->count();
    }

    /**
     * Etkin görevi olmayan, kullanımdaki makinede kullanımdaki planlar.
     *
     * @return Builder<CleaningPlan>
     */
    private function usablePlans(CleaningPlanKind $kind): Builder
    {
        return CleaningPlan::query()
            ->active()
            ->where('kind', $kind)
            ->whereHas('machine', fn (Builder $machine) => $machine->where('is_active', true))
            ->whereDoesntHave('activeTask');
    }

    private function open(CleaningPlan $plan, CarbonInterface $dueAt, CarbonImmutable $now, ?WorkOrder $trigger = null, ?WorkOrder $next = null): bool
    {
        try {
            DB::transaction(function () use ($plan, $dueAt, $now, $trigger, $next) {
                CleaningTask::openFor($plan, $dueAt, $trigger, $next);
                $plan->update(['last_task_at' => $now]);
            });
        } catch (UniqueConstraintViolationException) {
            return false; // Planın etkin görevi bu arada açıldı.
        }

        return true;
    }

    /**
     * Makinede kullanılabilen (makineye, hattına bağlı ya da bağlantısız) en erken planlanmış
     * üretim iş emri; tamamlanan emir hariç.
     */
    private function nextWorkOrder(Machine $machine, WorkOrder $completed): ?WorkOrder
    {
        /** @var Collection<int, WorkOrder> $candidates */
        $candidates = WorkOrder::query()
            ->where('status', WorkOrderStatus::Planned)
            ->whereKeyNot($completed->id)
            ->where(fn (Builder $query) => $query->whereNull('machine_id')->orWhere('machine_id', $machine->id))
            ->where(fn (Builder $query) => $query->whereNull('line_id')->orWhere('line_id', $machine->line_id))
            ->get();

        // Makineye bağlı olan hatta bağlı olandan, o da bağlantısızdan önce gelir; sonra planlanan başlangıç.
        return $candidates
            ->sortBy(fn (WorkOrder $order) => [
                $order->machine_id !== null ? 0 : ($order->line_id !== null ? 1 : 2),
                $order->planned_start_at?->getTimestamp() ?? PHP_INT_MAX,
                $order->code,
            ])
            ->first();
    }
}
