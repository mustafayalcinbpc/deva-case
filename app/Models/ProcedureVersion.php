<?php

namespace App\Models;

use App\Enums\DefinitionChangeAction;
use App\Models\Concerns\RecordsDefinitionChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use LogicException;

/**
 * Prosedürün bir versiyonu. `published_at` NULL ise taslaktır ve düzenlenebilir; doluysa
 * yayımlanmıştır ve bir daha değiştirilemez, silinemez, fazı ve adımı da değişmez (K-15, R-13).
 * Gelecek tarihli yayın, tarih gelene kadar yeni kayıtlara uygulanmaz (Procedure::currentVersion()).
 */
#[Fillable(['procedure_id', 'version', 'material_required', 'published_at'])]
class ProcedureVersion extends Model
{
    use RecordsDefinitionChanges;

    /**
     * "PRC-01 v2"
     */
    public function definitionChangeLabel(): string
    {
        return trim($this->procedure()->value('code')." v{$this->version}");
    }

    public function definitionChangeRoot(): Model
    {
        return $this->procedure()->first() ?? $this;
    }

    /**
     * K-15: taslağın yayın tarihinin dolması yayımlamadır.
     */
    protected function definitionChangeActions(): array
    {
        return [
            'published_at' => fn ($old, $new) => $old === null && $new !== null ? DefinitionChangeAction::Published : null,
        ];
    }

    protected function definitionChangeReferences(): array
    {
        return ['procedure_id' => Procedure::class];
    }

    protected function casts(): array
    {
        return [
            'material_required' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Taslağı yayımlamak (published_at'i doldurmak) son serbest değişikliktir.
        static::updating(function (self $version) {
            if ($version->getOriginal('published_at') !== null) {
                throw new LogicException('Yayımlanmış prosedür versiyonu değiştirilemez (K-15).');
            }

            static::assertDrafts([$version->getKey()], 'Yayımlanmış prosedür versiyonu değiştirilemez (K-15).');
        });

        static::deleting(function (self $version) {
            if ($version->getOriginal('published_at') !== null) {
                throw new LogicException('Yayımlanmış prosedür versiyonu silinemez (K-15).');
            }

            static::assertDrafts([$version->getKey()], 'Yayımlanmış prosedür versiyonu silinemez (K-15).');
        });
    }

    /**
     * Verilen versiyonlardan biri veritabanında yayımlanmışsa LogicException fırlatır.
     * Bellekteki model eskimiş olabilir; karar veritabanındaki duruma göre verilir.
     *
     * @param  array<int|string|null>  $ids
     */
    public static function assertDrafts(array $ids, string $message): void
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id !== null)));

        if ($ids !== [] && static::query()->whereKey($ids)->whereNotNull('published_at')->exists()) {
            throw new LogicException($message);
        }
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProcedurePhase::class)->orderBy('sequence');
    }

    public function steps(): HasManyThrough
    {
        return $this->hasManyThrough(ProcedureStep::class, ProcedurePhase::class);
    }

    /**
     * Bu versiyonla açılmış temizlik kayıtları (K-15).
     */
    public function cleanings(): HasMany
    {
        return $this->hasMany(Cleaning::class);
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * Yayımlanmış ama yayın tarihi henüz gelmemiş.
     */
    public function isScheduled(): bool
    {
        return $this->published_at !== null && $this->published_at->isFuture();
    }
}
