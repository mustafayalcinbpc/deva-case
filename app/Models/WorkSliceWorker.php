<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['work_slice_id', 'user_id', 'ended_at'])]
#[WithoutTimestamps]
class WorkSliceWorker extends Model
{
    use GuardsImmutableAttributes;

    protected function casts(): array
    {
        return [
            'ended_at' => 'immutable_datetime',
        ];
    }

    protected function immutableAttributes(): array
    {
        return ['work_slice_id', 'user_id', 'ended_at'];
    }

    public function slice(): BelongsTo
    {
        return $this->belongsTo(WorkSlice::class, 'work_slice_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
