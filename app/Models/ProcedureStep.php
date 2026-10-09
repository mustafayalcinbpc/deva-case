<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['procedure_phase_id', 'sequence', 'title', 'description', 'media_path'])]
class ProcedureStep extends Model
{
    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProcedurePhase::class, 'procedure_phase_id');
    }
}
