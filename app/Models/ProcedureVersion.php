<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['procedure_id', 'version', 'material_required', 'published_at'])]
class ProcedureVersion extends Model
{
    protected function casts(): array
    {
        return [
            'material_required' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProcedurePhase::class)->orderBy('sequence');
    }
}
