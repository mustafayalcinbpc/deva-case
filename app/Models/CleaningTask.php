<?php

namespace App\Models;

use App\Enums\CleaningTaskSource;
use App\Enums\CleaningTaskStatus;
use App\Models\Concerns\TransitionsStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Yapılması gereken temizlik (K-21, K-23). Kayıt değildir: kaydı operatör görevden açar
 * (CleaningWorkflow::open) ve kaydı açan sorumludur (R-15). Durum yalnızca CleaningTaskStatus
 * geçişleriyle değişir; open_plan_id durumla birlikte tutulur (setStatus).
 *
 * K-24: görev planlandığı an görünür. Müdahale vakti (scheduled_at) gelene kadar "ileride"dir ve
 * ondan kayıt açılmaz; üretim iş emri tetikli planda vakit, emir tamamlanınca belli olur. Son tarih
 * (due_at) vakit + planın gecikme toleransıdır; geçerse görev gecikmiştir.
 */
#[Fillable([
    'machine_id', 'cleaning_plan_id', 'source', 'trigger_work_order_id', 'work_order_id', 'scheduled_at', 'due_at',
    'status', 'open_plan_id', 'cleaning_id', 'closed_at', 'cancelled_by', 'cancel_reason',
])]
class CleaningTask extends Model
{
    use TransitionsStatus {
        transitionTo as private transitionStatus;
    }

    protected function casts(): array
    {
        return [
            'source' => CleaningTaskSource::class,
            'status' => CleaningTaskStatus::class,
            'scheduled_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Planın yeni açık görevi (K-20, K-21). Vakit NULL ise görev tetikleyen üretim iş emrinin
     * tamamlanmasını bekler (K-24). Planın zaten etkin görevi varsa open_plan_id unique index'i
     * UniqueConstraintViolationException fırlatır; görev üreten taraf bunu "zaten var" olarak ele alır.
     */
    public static function openFor(CleaningPlan $plan, ?CarbonInterface $scheduledAt, ?WorkOrder $trigger = null, ?WorkOrder $next = null): self
    {
        return static::create([
            'machine_id' => $plan->machine_id,
            'cleaning_plan_id' => $plan->id,
            'source' => $plan->kind->taskSource(),
            'trigger_work_order_id' => $trigger?->id,
            'work_order_id' => $next?->id,
            'scheduled_at' => $scheduledAt,
            'due_at' => $scheduledAt !== null ? $plan->dueAfter($scheduledAt) : null,
            'status' => CleaningTaskStatus::Open,
            'open_plan_id' => $plan->id,
        ]);
    }

    /**
     * K-24: tetik bekleyen görevin vakti belli oldu (üretim iş emri tamamlandı). Kaydetmez.
     */
    public function scheduleAt(CarbonInterface $scheduledAt, WorkOrder $trigger, ?WorkOrder $next): void
    {
        $this->scheduled_at = $scheduledAt;
        $this->due_at = $this->plan->dueAfter($scheduledAt);
        $this->trigger_work_order_id = $trigger->id;
        $this->work_order_id = $next?->id;
    }

    /**
     * Durumu değiştirir; etkin görevde planın tek etkin görev işaretini (open_plan_id) tutar.
     */
    public function transitionTo(CleaningTaskStatus $target): void
    {
        $this->transitionStatus($target);
        $this->open_plan_id = $target->isActive() ? $this->cleaning_plan_id : null;
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(CleaningPlan::class, 'cleaning_plan_id');
    }

    /**
     * Görevi doğuran (tamamlanan) üretim iş emri.
     */
    public function triggerWorkOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'trigger_work_order_id');
    }

    /**
     * Temizliğin hazırladığı sonraki üretim iş emri; kayıt açılırken forma gelir.
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Görevi iptal eden yönetici (K-23).
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Görevden açılmış güncel kayıt (görev yeniden açılınca boşalır).
     */
    public function cleaning(): BelongsTo
    {
        return $this->belongsTo(Cleaning::class);
    }

    /**
     * Bu görevden açılmış bütün kayıtlar (düşenler dahil).
     */
    public function cleanings(): HasMany
    {
        return $this->hasMany(Cleaning::class);
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', CleaningTaskStatus::Open);
    }

    /**
     * Görevin nedeni: tetik bekleyen görevde emir henüz tamamlanmamıştır.
     */
    public function reasonLabel(): string
    {
        return $this->source === CleaningTaskSource::WorkOrder && $this->scheduled_at === null
            ? 'Üretim iş emri tamamlanınca'
            : $this->source->label();
    }

    /**
     * Açık ve müdahale vakti gelmiş: görevden kayıt açılabilir.
     */
    public function isDue(CarbonInterface $now): bool
    {
        return $this->status === CleaningTaskStatus::Open && $this->scheduled_at !== null && $this->scheduled_at->lte($now);
    }

    /**
     * Açık ama müdahale vakti gelmemiş ya da henüz belli değil (tetik bekleniyor).
     */
    public function isUpcoming(CarbonInterface $now): bool
    {
        return $this->status === CleaningTaskStatus::Open && ! $this->isDue($now);
    }

    /**
     * Vakti belli değilse son tarih de yoktur; görev gecikmiş sayılmaz.
     */
    public function isOverdue(CarbonInterface $now): bool
    {
        return $this->status === CleaningTaskStatus::Open && $this->due_at !== null && $this->due_at->lt($now);
    }
}
