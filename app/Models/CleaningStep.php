<?php

namespace App\Models;

use App\Enums\StepStatus;
use App\Models\Concerns\GuardsImmutableAttributes;
use App\Models\Concerns\TransitionsStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'cleaning_id', 'cleaning_phase_id', 'procedure_step_id', 'sequence', 'status',
    'started_at', 'completed_at',
])]
class CleaningStep extends Model
{
    use GuardsImmutableAttributes, TransitionsStatus;

    protected function casts(): array
    {
        return [
            'status' => StepStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    protected function immutableAttributes(): array
    {
        return [
            'cleaning_id', 'cleaning_phase_id', 'procedure_step_id', 'sequence', 'started_at',
            'completed_at',
        ];
    }

    public function cleaning(): BelongsTo
    {
        return $this->belongsTo(Cleaning::class);
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(CleaningPhase::class, 'cleaning_phase_id');
    }

    public function procedureStep(): BelongsTo
    {
        return $this->belongsTo(ProcedureStep::class);
    }

    public function assignees(): HasMany
    {
        return $this->hasMany(CleaningStepAssignee::class);
    }

    public function activeAssignees(): HasMany
    {
        return $this->assignees()->whereNull('removed_at');
    }

    public function slices(): HasMany
    {
        return $this->hasMany(WorkSlice::class)->orderBy('started_at');
    }

    public function openSlice(): HasOne
    {
        return $this->hasOne(WorkSlice::class)->whereNull('ended_at');
    }

    public function isAssigned(User $user): bool
    {
        return $this->activeAssignees()->where('user_id', $user->id)->exists();
    }
}
