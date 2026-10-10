<?php

namespace App\Models;

use App\Enums\CleaningPlanKind;
use App\Enums\DefinitionChangeAction;
use App\Models\Concerns\RecordsDefinitionChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Temizlik planı (K-20): makine bazında periyodik kural (interval_days günde bir) ya da
 * "makinedeki üretim iş emri tamamlanınca" tetiği. Plan yapılması gereken temizliği görev
 * olarak üretir; aynı anda tek etkin (açık ya da kayda bağlı) görevi olur. Plan silinmez,
 * kullanımdan kaldırılır.
 */
#[Fillable(['machine_id', 'kind', 'interval_days', 'is_active', 'last_task_at'])]
class CleaningPlan extends Model
{
    use RecordsDefinitionChanges;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'kind' => CleaningPlanKind::class,
            'interval_days' => 'integer',
            'is_active' => 'boolean',
            'last_task_at' => 'immutable_datetime',
        ];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(CleaningTask::class);
    }

    /**
     * Açık ya da kayda bağlı görev (en fazla bir tane; cleaning_tasks.open_plan_id unique).
     */
    public function activeTask(): HasOne
    {
        return $this->hasOne(CleaningTask::class, 'open_plan_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function definitionChangeLabel(): string
    {
        $machine = Machine::query()->whereKey($this->machine_id)->value('code');

        return collect([$machine, $this->kind?->label()])->filter()->implode(' · ');
    }

    protected function definitionChangeReferences(): array
    {
        return ['machine_id' => Machine::class];
    }

    protected function definitionChangeActions(): array
    {
        return [
            'is_active' => fn ($old, $new) => $new ? DefinitionChangeAction::Activated : DefinitionChangeAction::Deactivated,
        ];
    }
}
