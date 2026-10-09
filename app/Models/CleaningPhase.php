<?php

namespace App\Models;

use App\Enums\PhaseStatus;
use App\Models\Concerns\GuardsImmutableAttributes;
use App\Models\Concerns\TransitionsStatus;
use App\Services\Cleaning\WorkTime;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'cleaning_id', 'procedure_phase_id', 'sequence', 'status', 'started_at', 'completed_at',
    'measured_seconds', 'below_minimum', 'deviation_reason',
])]
class CleaningPhase extends Model
{
    use GuardsImmutableAttributes, TransitionsStatus;

    protected function casts(): array
    {
        return [
            'status' => PhaseStatus::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'below_minimum' => 'boolean',
        ];
    }

    protected function immutableAttributes(): array
    {
        return [
            'cleaning_id', 'procedure_phase_id', 'sequence', 'started_at', 'completed_at',
            'measured_seconds', 'deviation_reason',
        ];
    }

    public function cleaning(): BelongsTo
    {
        return $this->belongsTo(Cleaning::class);
    }

    public function procedurePhase(): BelongsTo
    {
        return $this->belongsTo(ProcedurePhase::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CleaningStep::class)->orderBy('sequence');
    }

    public function slices(): HasManyThrough
    {
        return $this->hasManyThrough(WorkSlice::class, CleaningStep::class);
    }

    /**
     * Minimum süre kontrolünde kullanılan süre: fazın ayarına göre net ya da brüt (K-02).
     */
    public function measuredSeconds(): int
    {
        $slices = $this->slices()->get();

        return $this->procedurePhase->include_gaps ? WorkTime::gross($slices) : WorkTime::net($slices);
    }
}
