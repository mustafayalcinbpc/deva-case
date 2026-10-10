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
 */
#[Fillable([
    'machine_id', 'cleaning_plan_id', 'source', 'trigger_work_order_id', 'work_order_id', 'due_at',
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
            'due_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    /**
     * Planın yeni açık görevi (K-20, K-21). Planın zaten etkin görevi varsa open_plan_id unique
     * index'i UniqueConstraintViolationException fırlatır; görev üreten taraf bunu "zaten var"
     * olarak ele alır.
     */
    public static function openFor(CleaningPlan $plan, CarbonInterface $dueAt, ?WorkOrder $trigger = null, ?WorkOrder $next = null): self
    {
        return static::create([
            'machine_id' => $plan->machine_id,
            'cleaning_plan_id' => $plan->id,
            'source' => $plan->kind->taskSource(),
            'trigger_work_order_id' => $trigger?->id,
            'work_order_id' => $next?->id,
            'due_at' => $dueAt,
            'status' => CleaningTaskStatus::Open,
            'open_plan_id' => $plan->id,
        ]);
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

    public function isOverdue(CarbonInterface $now): bool
    {
        return $this->status === CleaningTaskStatus::Open && $this->due_at->lt($now);
    }
}
