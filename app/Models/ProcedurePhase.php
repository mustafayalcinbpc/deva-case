<?php

namespace App\Models;

use App\Models\Concerns\RecordsDefinitionChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Prosedür versiyonunun fazı (R-03, R-06, K-02). Yalnızca taslak versiyonda eklenir,
 * değiştirilir ve silinir; yayımlanmış versiyonun fazı sabittir (K-15).
 */
#[Fillable(['procedure_version_id', 'sequence', 'name', 'min_duration_seconds', 'include_gaps'])]
class ProcedurePhase extends Model
{
    use RecordsDefinitionChanges;

    /**
     * "PRC-01 v2 · Ön yıkama"
     */
    public function definitionChangeLabel(): string
    {
        $version = ProcedureVersion::query()->find($this->procedure_version_id);

        return collect([$version?->definitionChangeLabel(), $this->name])->filter()->implode(' · ');
    }

    public function definitionChangeRoot(): Model
    {
        return Procedure::query()
            ->whereHas('versions', fn (Builder $query) => $query->whereKey($this->procedure_version_id))
            ->first() ?? $this;
    }

    protected function definitionChangeReferences(): array
    {
        return ['procedure_version_id' => ProcedureVersion::class];
    }

    protected function casts(): array
    {
        return [
            'include_gaps' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $phase) => ProcedureVersion::assertDrafts(
            [$phase->procedure_version_id],
            'Yayımlanmış prosedür versiyonuna faz eklenemez (K-15).',
        ));

        static::updating(fn (self $phase) => ProcedureVersion::assertDrafts(
            [$phase->getOriginal('procedure_version_id'), $phase->procedure_version_id],
            'Yayımlanmış prosedür versiyonunun fazı değiştirilemez (K-15).',
        ));

        static::deleting(fn (self $phase) => ProcedureVersion::assertDrafts(
            [$phase->getOriginal('procedure_version_id')],
            'Yayımlanmış prosedür versiyonunun fazı silinemez (K-15).',
        ));
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
