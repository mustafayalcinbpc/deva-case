<?php

namespace App\Models;

use App\Enums\CancelReason;
use App\Enums\CleaningStatus;
use App\Enums\CleaningType;
use App\Models\Concerns\GuardsImmutableAttributes;
use App\Models\Concerns\TransitionsStatus;
use App\Services\Cleaning\WorkTime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'record_no', 'field_ref', 'type', 'status', 'facility_id', 'line_id', 'machine_id',
    'procedure_version_id', 'owner_id', 'work_order_id', 'cleaning_task_id', 'notes', 'started_at', 'closed_at',
    'cancelled_by', 'cancel_reason', 'cancel_note',
])]
class Cleaning extends Model
{
    use GuardsImmutableAttributes, TransitionsStatus;

    protected function casts(): array
    {
        return [
            'type' => CleaningType::class,
            'status' => CleaningStatus::class,
            'cancel_reason' => CancelReason::class,
            'started_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected function immutableAttributes(): array
    {
        // owner_id: sorumluluk devredilemez (R-15).
        return [
            'record_no', 'field_ref', 'type', 'facility_id', 'line_id', 'machine_id',
            'procedure_version_id', 'owner_id', 'work_order_id', 'notes', 'started_at',
            'closed_at', 'cancelled_by', 'cancel_reason', 'cancel_note', 'created_at',
        ];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function procedureVersion(): BelongsTo
    {
        return $this->belongsTo(ProcedureVersion::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    /**
     * Kaydın açıldığı görev (K-21); görevsiz açılan kayıtta yok.
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(CleaningTask::class, 'cleaning_task_id');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(CleaningPhase::class)->orderBy('sequence');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CleaningStep::class)->orderBy('sequence');
    }

    public function slices(): HasManyThrough
    {
        return $this->hasManyThrough(WorkSlice::class, CleaningStep::class);
    }

    public function materials(): HasMany
    {
        return $this->hasMany(CleaningMaterial::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CleaningEvent::class)->orderBy('sequence');
    }

    /**
     * K-05: kayıt açılırken kullanıcıyı uyarmak için aynı makinedeki başlamamış kayıtlar.
     */
    /**
     * @param  Machine|iterable<Machine|int>  $machines
     */
    public function scopePendingOn(Builder $query, Machine|iterable $machines): void
    {
        $ids = $machines instanceof Machine
            ? [$machines->id]
            : collect($machines)->map(fn ($machine) => $machine instanceof Machine ? $machine->id : (int) $machine)->all();

        $query->whereIn('machine_id', $ids)->where('status', CleaningStatus::Created);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    /**
     * Adımlarda gerçekten çalışılan süre; adımlar arasındaki boşluklar sayılmaz (K-02).
     */
    public function netSeconds(): int
    {
        return WorkTime::net($this->slices()->get());
    }

    /**
     * İlk çalışma diliminin başlangıcından son dilimin bitişine kadar geçen süre (K-02).
     */
    public function grossSeconds(): int
    {
        return WorkTime::gross($this->slices()->get());
    }

    /**
     * Toplam insan eforu: her dilimde süre × kişi sayısı (R-26, K-04).
     */
    public function effortSeconds(): int
    {
        return WorkTime::effort($this->slices()->withCount('workers')->get());
    }
}
