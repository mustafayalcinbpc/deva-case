<?php

namespace App\Models;

use App\Models\Concerns\RecordsDefinitionChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Prosedür versiyonunun beklediği malzeme (K-13). Zorunlu malzemenin her biri için geçerli bir
 * lot girilmeden ilk adım başlatılamaz (K-12, R-09). Yalnızca taslakta eklenir, değiştirilir ve
 * silinir; yayımlanmış versiyonun listesi sabittir (K-15).
 */
#[Fillable(['procedure_version_id', 'material_id', 'sequence', 'is_required'])]
class ProcedureVersionMaterial extends Model
{
    use RecordsDefinitionChanges;

    protected $attributes = [
        'is_required' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $item) => ProcedureVersion::assertDrafts(
            [$item->procedure_version_id],
            'Yayımlanmış prosedür versiyonuna malzeme eklenemez (K-15).',
        ));

        static::updating(fn (self $item) => ProcedureVersion::assertDrafts(
            [$item->getOriginal('procedure_version_id'), $item->procedure_version_id],
            'Yayımlanmış prosedür versiyonunun malzemesi değiştirilemez (K-15).',
        ));

        static::deleting(fn (self $item) => ProcedureVersion::assertDrafts(
            [$item->getOriginal('procedure_version_id')],
            'Yayımlanmış prosedür versiyonunun malzemesi silinemez (K-15).',
        ));
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ProcedureVersion::class, 'procedure_version_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * "PRC-01 v2 · DET-01"
     */
    public function definitionChangeLabel(): string
    {
        $version = ProcedureVersion::query()->find($this->procedure_version_id);

        return collect([$version?->definitionChangeLabel(), Material::query()->whereKey($this->material_id)->value('code')])->filter()->implode(' · ');
    }

    public function definitionChangeRoot(): Model
    {
        return Procedure::query()
            ->whereHas('versions', fn (Builder $query) => $query->whereKey($this->procedure_version_id))
            ->first() ?? $this;
    }

    protected function definitionChangeReferences(): array
    {
        return ['procedure_version_id' => ProcedureVersion::class, 'material_id' => Material::class];
    }
}
