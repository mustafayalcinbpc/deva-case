<?php

namespace App\Models;

use App\Enums\DefinitionChangeAction;
use App\Models\Concerns\RecordsDefinitionChanges;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Malzemenin bir partisi (K-14): lot no ve son kullanma tarihi burada bir kez tanımlanır
 * (gerçekte depo/ERP). Operatör kayıtta yalnızca kullanımdaki ve SKT'si geçmemiş lotu seçer;
 * kayıttaki satır lot no ve SKT'nin seçildiği andaki kopyasını taşır. Lot silinmez.
 */
#[Fillable(['material_id', 'lot_no', 'expiry_date', 'received_at', 'is_active'])]
class MaterialLot extends Model
{
    use RecordsDefinitionChanges;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'immutable_date',
            'received_at' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    public function cleaningMaterials(): HasMany
    {
        return $this->hasMany(CleaningMaterial::class);
    }

    /**
     * K-14: SKT verilen günden önceyse lot kullanılamaz; SKT günü geçerlidir.
     */
    public function isExpiredOn(CarbonInterface $day): bool
    {
        return $this->expiry_date->toDateString() < $day->toDateString();
    }

    /**
     * Verilen gün yeni girişte seçilebilen lotlar: lot ve malzemesi kullanımda, SKT geçmemiş.
     */
    public function scopeUsableOn(Builder $query, CarbonInterface $day): void
    {
        $query->where('is_active', true)
            ->whereDate('expiry_date', '>=', $day->toDateString())
            ->whereHas('material', fn (Builder $material) => $material->where('is_active', true));
    }

    /**
     * "DET-01 / DT-24118"
     */
    public function definitionChangeLabel(): string
    {
        return collect([Material::query()->whereKey($this->material_id)->value('code'), $this->lot_no])->filter()->implode(' / ');
    }

    public function definitionChangeRoot(): Model
    {
        return Material::query()->find($this->material_id) ?? $this;
    }

    protected function definitionChangeReferences(): array
    {
        return ['material_id' => Material::class];
    }

    protected function definitionChangeActions(): array
    {
        return [
            'is_active' => fn ($old, $new) => $new ? DefinitionChangeAction::Activated : DefinitionChangeAction::Deactivated,
        ];
    }
}
