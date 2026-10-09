<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cleaning_id', 'material_id', 'lot_no', 'expiry_date', 'added_by', 'voided_at', 'voided_by',
    'void_reason',
])]
class CleaningMaterial extends Model
{
    use GuardsImmutableAttributes;

    protected function casts(): array
    {
        return [
            'expiry_date' => 'immutable_date',
            'voided_at' => 'immutable_datetime',
        ];
    }

    protected function immutableAttributes(): array
    {
        return [
            'cleaning_id', 'material_id', 'lot_no', 'expiry_date', 'added_by', 'voided_at',
            'voided_by', 'void_reason',
        ];
    }

    public function cleaning(): BelongsTo
    {
        return $this->belongsTo(Cleaning::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function scopeValid(Builder $query): void
    {
        $query->whereNull('voided_at');
    }
}
