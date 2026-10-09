<?php

namespace App\Models;

use App\Models\Concerns\RecordsDefinitionChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Fazın sıralı adımı (R-03, R-04); isteğe bağlı fotoğraf ya da video `public` diskte durur
 * (R-05). Yalnızca taslak versiyonda eklenir, değiştirilir ve silinir (K-15).
 */
#[Fillable(['procedure_phase_id', 'sequence', 'title', 'description', 'media_path'])]
class ProcedureStep extends Model
{
    use RecordsDefinitionChanges;

    /** Video olarak gösterilen uzantılar; diğer medya görsel sayılır (CleaningDetailController ile aynı). */
    public const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogg', 'ogv', 'mov', 'm4v'];

    /**
     * "PRC-01 v2 · Ön yıkama · Kapağı sök"
     */
    public function definitionChangeLabel(): string
    {
        $phase = ProcedurePhase::query()->find($this->procedure_phase_id);

        return collect([$phase?->definitionChangeLabel(), $this->title])->filter()->implode(' · ');
    }

    public function definitionChangeRoot(): Model
    {
        return Procedure::query()
            ->whereHas('versions.phases', fn (Builder $query) => $query->whereKey($this->procedure_phase_id))
            ->first() ?? $this;
    }

    protected function definitionChangeReferences(): array
    {
        return ['procedure_phase_id' => ProcedurePhase::class];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $step) => static::assertDraftPhases(
            [$step->procedure_phase_id],
            'Yayımlanmış prosedür versiyonuna adım eklenemez (K-15).',
        ));

        static::updating(fn (self $step) => static::assertDraftPhases(
            [$step->getOriginal('procedure_phase_id'), $step->procedure_phase_id],
            'Yayımlanmış prosedür versiyonunun adımı değiştirilemez (K-15).',
        ));

        static::deleting(fn (self $step) => static::assertDraftPhases(
            [$step->getOriginal('procedure_phase_id')],
            'Yayımlanmış prosedür versiyonunun adımı silinemez (K-15).',
        ));
    }

    /**
     * @param  array<int|string|null>  $phaseIds
     */
    private static function assertDraftPhases(array $phaseIds, string $message): void
    {
        $phaseIds = array_values(array_unique(array_filter($phaseIds, fn ($id) => $id !== null)));

        $published = $phaseIds !== [] && ProcedurePhase::query()
            ->whereKey($phaseIds)
            ->whereHas('version', fn (Builder $query) => $query->whereNotNull('published_at'))
            ->exists();

        if ($published) {
            throw new LogicException($message);
        }
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(ProcedurePhase::class, 'procedure_phase_id');
    }

    public function mediaUrl(): ?string
    {
        return $this->media_path ? Storage::disk('public')->url($this->media_path) : null;
    }

    /**
     * 'image' | 'video' | NULL (medya yok).
     */
    public function mediaType(): ?string
    {
        if (! $this->media_path) {
            return null;
        }

        $extension = strtolower(pathinfo($this->media_path, PATHINFO_EXTENSION));

        return in_array($extension, self::VIDEO_EXTENSIONS, true) ? 'video' : 'image';
    }
}
