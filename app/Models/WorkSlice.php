<?php

namespace App\Models;

use App\Enums\SliceEndReason;
use App\Models\Concerns\GuardsImmutableAttributes;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bir adımın kesintisiz çalışılan bölümü (K-03). Duraklatma ya da görevli
 * değişikliği dilimi kapatır; devam etme ya da yeni görevli listesi yeni dilim açar.
 */
#[Fillable(['cleaning_step_id', 'started_at', 'started_by', 'ended_at', 'ended_by', 'end_reason'])]
#[WithoutTimestamps]
class WorkSlice extends Model
{
    use GuardsImmutableAttributes;

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'end_reason' => SliceEndReason::class,
        ];
    }

    protected function immutableAttributes(): array
    {
        return ['cleaning_step_id', 'started_at', 'started_by', 'ended_at', 'ended_by', 'end_reason'];
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(CleaningStep::class, 'cleaning_step_id');
    }

    public function workers(): HasMany
    {
        return $this->hasMany(WorkSliceWorker::class);
    }

    /**
     * Açık dilim şu ana kadar geçen süreyle hesaplanır.
     */
    public function endOrNow(): CarbonInterface
    {
        return $this->ended_at ?? now();
    }

    public function durationSeconds(): int
    {
        return (int) $this->started_at->diffInSeconds($this->endOrNow());
    }

    public function workerCount(): int
    {
        return $this->workers_count ?? $this->workers()->count();
    }

    /**
     * K-03: duraklatılması unutulmuş olabilecek, eşikten uzun dilim.
     */
    public function isAnomalous(): bool
    {
        return $this->durationSeconds() > config('cleaning.slice_anomaly_hours') * 3600;
    }
}
