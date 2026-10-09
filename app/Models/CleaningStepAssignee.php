<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cleaning_step_id', 'user_id', 'assigned_by', 'assigned_at', 'removed_by', 'removed_at'])]
#[WithoutTimestamps]
class CleaningStepAssignee extends Model
{
    use GuardsImmutableAttributes;

    protected function casts(): array
    {
        return [
            'assigned_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
        ];
    }

    protected function immutableAttributes(): array
    {
        return ['cleaning_step_id', 'user_id', 'assigned_by', 'assigned_at', 'removed_by', 'removed_at'];
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(CleaningStep::class, 'cleaning_step_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
