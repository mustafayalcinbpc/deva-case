<?php

namespace App\Models;

use App\Enums\DefinitionChangeAction;
use App\Models\Concerns\RecordsDefinitionChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Yöneticinin tanımladığı malzeme kataloğu (K-13). Malzeme silinmez; kullanımdan kaldırılan
 * malzeme yeni girişlerde seçilemez, geçmiş kayıtlarda görünmeye devam eder.
 */
#[Fillable(['code', 'name', 'is_active'])]
class Material extends Model
{
    use RecordsDefinitionChanges;

    /** Veritabanı varsayılanıyla aynı; yeni oluşturulan model de kullanımda sayılır. */
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Kayıtlara girilen malzeme satırları (geçersiz kılınanlar dahil).
     */
    public function cleaningMaterials(): HasMany
    {
        return $this->hasMany(CleaningMaterial::class);
    }

    /**
     * Malzemenin partileri (K-14).
     */
    public function lots(): HasMany
    {
        return $this->hasMany(MaterialLot::class)->orderBy('expiry_date')->orderBy('lot_no');
    }

    /**
     * Yeni kayıtta ve malzeme ekleme formunda seçilebilen malzemeler.
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function definitionChangeLabel(): string
    {
        return (string) $this->code;
    }

    /**
     * Kullanımdan kaldırma ve yeniden kullanıma alma.
     */
    protected function definitionChangeActions(): array
    {
        return [
            'is_active' => fn ($old, $new) => $new ? DefinitionChangeAction::Activated : DefinitionChangeAction::Deactivated,
        ];
    }
}
