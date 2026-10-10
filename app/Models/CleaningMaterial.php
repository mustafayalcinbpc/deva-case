<?php

namespace App\Models;

use App\Models\Concerns\GuardsImmutableAttributes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kayıtta kullanılan malzeme satırı: malzeme, lot ve SKT'nin seçildiği andaki kopyası (K-14).
 * Silinmez, değiştirilmez; yanlışsa gerekçeyle geçersiz kılınır (K-12).
 */
#[Fillable([
    'cleaning_id', 'material_id', 'material_lot_id', 'lot_no', 'expiry_date', 'added_by', 'voided_at',
    'voided_by', 'void_reason',
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
            'cleaning_id', 'material_id', 'material_lot_id', 'lot_no', 'expiry_date', 'added_by',
            'voided_at', 'voided_by', 'void_reason',
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

    /**
     * Seçilen lot (K-14). lot_no ve expiry_date bu lotun seçildiği andaki kopyasıdır; eski
     * satırlarda lot bağlantısı yoktur.
     */
    public function lot(): BelongsTo
    {
        return $this->belongsTo(MaterialLot::class, 'material_lot_id');
    }

    public function scopeValid(Builder $query): void
    {
        $query->whereNull('voided_at');
    }
}
