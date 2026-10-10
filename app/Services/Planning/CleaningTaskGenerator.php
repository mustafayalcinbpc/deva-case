<?php

namespace App\Services\Planning;

use App\Enums\CleaningPlanKind;
use App\Enums\CleaningTaskStatus;
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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Temizlik planlarından görev üretimi (K-20, K-21, K-24) ve geciken görevlerin bildirimi (K-23).
 *
 * Görev planlandığı an açılır ve personelin önüne "ileride" olarak düşer; müdahale vakti gelince
 * ondan kayıt açılabilir. Bir planın aynı anda tek etkin görevi olur (cleaning_tasks.open_plan_id
 * unique). Etkin görevi olan plana yeni görev açılmaz; eşzamanlı iki üretimden biri unique index'e
 * takılır ve "zaten var" sayılır. Kullanımdan kaldırılmış plan ve makine görev üretmez.
 */
class CleaningTaskGenerator
{
    /**
     * Toplu üretim kilidi: ekranlar ve dakikalık komut aynı anda çalışırsa sıraya girer; demo
     * verisi yüklenirken (DemoSeeder) üretim durur.
     */
    public const LOCK = 'cleaning-tasks:generate';

    /**
     * Etkin görevi olmayan planların sıradaki görevini açar:
     * - periyodik plan: vakit, son görevin vaktinden bir aralık sonrası; geride kaldıysa ileriye
     *   doğru aralık aralık kaydırılır (hiç görevi yoksa hemen);
     * - "üretim iş emri tamamlanınca" planı: makinede bekleyen (üretimde ya da planlanmış) emir
     *   varsa vakti belli olmayan görev; emir tamamlanınca vakti belli olur (forCompletedWorkOrder).
     *
     * Kilit beş saniyede alınamazsa (ör. demo verisi yükleniyor) bir şey yapmaz; dakikalık komut
     * sonra yakalar.
     *
     * @return int açılan görev sayısı
     */
    public function generate(CarbonInterface $now): int
    {
        $lock = Cache::lock(self::LOCK, 60);

        // Lock::block() bekleme süresini uygulama saatiyle ölçer; saat dondurulmuşsa (demo verisi,
        // testler) hiç bitmez. Bekleme deneme sayısıyla sınırlanır: 25 × 200 ms.
        for ($attempt = 0; ! $lock->get(); $attempt++) {
            if ($attempt === 24) {
                return 0;
            }

            usleep(200_000);
        }

        try {
            return $this->generateUnlocked(CarbonImmutable::instance($now));
        } finally {
            $lock->release();
        }
    }

    /**
     * Kilit tutulurken çağrılır (generate ya da kilidi kendisi tutan DemoSeeder).
     */
    public function generateUnlocked(CarbonImmutable $now): int
    {
        $opened = 0;

        $periodic = $this->usablePlans(CleaningPlanKind::Periodic)
            ->whereDoesntHave('activeTask')
            ->whereNotNull('interval_days')
            ->orderBy('id')
            ->get();

        foreach ($periodic as $plan) {
            $opened += $this->open($plan, $this->nextSlot($plan, $now), $now) ? 1 : 0;
        }

        $triggered = $this->usablePlans(CleaningPlanKind::WorkOrderCompleted)
            ->whereDoesntHave('activeTask')
            ->with('machine')
            ->orderBy('id')
            ->get();

        foreach ($triggered as $plan) {
            $pending = $this->pendingWorkOrder($plan->machine);

            if ($pending !== null) {
                $opened += $this->open($plan, null, $now, $pending, $this->nextWorkOrder($plan->machine, $pending)) ? 1 : 0;
            }
        }

        return $opened;
    }

    /**
     * "Üretim iş emri tamamlanınca" kurallı planlar: iş emrinin makinesindeki ya da (makineye
     * bağlı değilse) hattındaki makinelerdeki planlar. Vakti belli olmayan görev bu emirle vakit
     * kazanır; etkin görevi olmayan plana vakti tamamlanma anı olan görev açılır. Vakti zaten
     * belli ya da kayda bağlı görev değişmez. Sonraki emir, makinede kullanılabilen en erken
     * planlanmış emirdir.
     *
     * @return int vakti belli olan görev sayısı
     */
    public function forCompletedWorkOrder(WorkOrder $workOrder): int
    {
        if ($workOrder->status !== WorkOrderStatus::Completed || ($workOrder->machine_id === null && $workOrder->line_id === null)) {
            return 0;
        }

        $now = CarbonImmutable::now();
        $completedAt = $workOrder->completed_at ?? $now;
        $plans = $this->usablePlans(CleaningPlanKind::WorkOrderCompleted)
            ->whereHas('machine', fn (Builder $machine) => $workOrder->machine_id !== null
                ? $machine->whereKey($workOrder->machine_id)
                : $machine->where('line_id', $workOrder->line_id))
            ->with(['machine', 'activeTask'])
            ->orderBy('id')
            ->get();

        $scheduled = 0;

        foreach ($plans as $plan) {
            $next = $this->nextWorkOrder($plan->machine, $workOrder);

            if ($plan->activeTask === null && $this->open($plan, $completedAt, $now, $workOrder, $next)) {
                $scheduled++;

                continue;
            }

            // Etkin görev vardı ya da bu arada açıldı (generate bu emri hâlâ bekleniyor sanmış olabilir).
            $task = $plan->activeTask ?? $plan->activeTask()->first();

            if ($task !== null && $task->scheduled_at === null && $this->schedule($plan, $task, $completedAt, $now, $workOrder, $next)) {
                $scheduled++;
            }
        }

        return $scheduled;
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
     * Kullanımdaki makinede kullanımdaki planlar.
     *
     * @return Builder<CleaningPlan>
     */
    private function usablePlans(CleaningPlanKind $kind): Builder
    {
        return CleaningPlan::query()
            ->active()
            ->where('kind', $kind)
            ->whereHas('machine', fn (Builder $machine) => $machine->where('is_active', true));
    }

    private function open(CleaningPlan $plan, ?CarbonInterface $scheduledAt, CarbonImmutable $now, ?WorkOrder $trigger = null, ?WorkOrder $next = null): bool
    {
        try {
            DB::transaction(function () use ($plan, $scheduledAt, $now, $trigger, $next) {
                CleaningTask::openFor($plan, $scheduledAt, $trigger, $next);
                $this->touchPlan($plan, $now);
            });
        } catch (UniqueConstraintViolationException) {
            return false; // Planın etkin görevi bu arada açıldı.
        }

        return true;
    }

    /**
     * Tetik bekleyen görevin vakti belli olur. Görev satırı kilitlenir; bu arada başka bir emirle
     * vakit kazanmış, kayda bağlanmış ya da iptal edilmiş görev değişmez.
     */
    private function schedule(CleaningPlan $plan, CleaningTask $task, CarbonInterface $scheduledAt, CarbonImmutable $now, WorkOrder $trigger, ?WorkOrder $next): bool
    {
        return DB::transaction(function () use ($plan, $task, $scheduledAt, $now, $trigger, $next) {
            $locked = CleaningTask::query()->lockForUpdate()->find($task->id);

            if ($locked?->status !== CleaningTaskStatus::Open || $locked->scheduled_at !== null) {
                return false;
            }

            $locked->setRelation('plan', $plan);
            $locked->scheduleAt($scheduledAt, $trigger, $next);
            $locked->save();
            $this->touchPlan($plan, $now);

            return true;
        });
    }

    /**
     * Planın son görev zamanı sistemin tuttuğu bir kayıttır, tanım değişikliği değildir: günlüğe
     * yazılmaz (yazılsaydı planı ekleyen yöneticinin adına ikinci bir "güncellendi" satırı düşerdi).
     */
    private function touchPlan(CleaningPlan $plan, CarbonImmutable $now): void
    {
        $plan->forceFill(['last_task_at' => $now])->saveQuietly();
    }

    /**
     * Periyodik planın sıradaki vakti: son görevin vaktinden bir aralık sonrası. Temizlik
     * gecikmeli yapıldıysa ya da plan bir süre kullanımda değildiyse geride kalan vakitler atlanır.
     */
    private function nextSlot(CleaningPlan $plan, CarbonImmutable $now): CarbonImmutable
    {
        $last = $plan->tasks()->max('scheduled_at');

        if ($last === null) {
            return $now;
        }

        $slot = CarbonImmutable::parse($last, 'UTC')->addDays($plan->interval_days);

        while ($slot->lessThan($now)) {
            $slot = $slot->addDays($plan->interval_days);
        }

        return $slot;
    }

    /**
     * Makinede tamamlanması beklenen üretim iş emri: makineye ya da hattına bağlı, üretimde olan
     * önce, sonra planlanmış en erken başlayan. Bağlantısız emir planları tetiklemez.
     */
    private function pendingWorkOrder(Machine $machine): ?WorkOrder
    {
        return WorkOrder::query()
            ->whereIn('status', [WorkOrderStatus::InProduction, WorkOrderStatus::Planned])
            ->where(fn (Builder $query) => $query
                ->where('machine_id', $machine->id)
                ->orWhere(fn (Builder $query) => $query->whereNull('machine_id')->where('line_id', $machine->line_id)))
            ->get()
            ->sortBy(fn (WorkOrder $order) => [
                $order->status === WorkOrderStatus::InProduction ? 0 : 1,
                $order->planned_start_at?->getTimestamp() ?? PHP_INT_MAX,
                $order->code,
            ])
            ->first();
    }

    /**
     * Makinede kullanılabilen (makineye, hattına bağlı ya da bağlantısız) en erken planlanmış
     * üretim iş emri; temizliği gerektiren (tamamlanan ya da tamamlanması beklenen) emir hariç.
     */
    private function nextWorkOrder(Machine $machine, WorkOrder $trigger): ?WorkOrder
    {
        /** @var Collection<int, WorkOrder> $candidates */
        $candidates = WorkOrder::query()
            ->where('status', WorkOrderStatus::Planned)
            ->whereKeyNot($trigger->id)
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
