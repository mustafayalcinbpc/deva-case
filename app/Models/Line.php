<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['facility_id', 'code', 'name'])]
class Line extends Model
{
    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }
}
