<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['procedure_version_id', 'sequence', 'name', 'min_duration_seconds', 'include_gaps'])]
class ProcedurePhase extends Model
{
    protected function casts(): array
    {
        return [
            'include_gaps' => 'boolean',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProcedureVersion::class, 'procedure_version_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ProcedureStep::class)->orderBy('sequence');
    }
}
