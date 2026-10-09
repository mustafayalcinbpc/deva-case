<?php

namespace App\Models;

use App\Models\Concerns\RecordsDefinitionChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Değişiklik günlüğünde versiyon, faz ve adım değişiklikleri de prosedürün geçmişinde görünür.
 */
#[Fillable(['code', 'name'])]
class Procedure extends Model
{
    use RecordsDefinitionChanges;

    public function definitionChangeLabel(): string
    {
        return (string) $this->code;
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProcedureVersion::class);
    }

    /**
     * Yeni kayıtlara uygulanacak versiyon: yayın tarihi gelmiş en son versiyon (K-15).
     * İleri tarihli yayımlanan versiyon, o tarihe kadar yeni kayıtlara uygulanmaz.
     */
    public function currentVersion(): ?ProcedureVersion
    {
        return $this->versions()->where('published_at', '<=', now())->orderByDesc('version')->first();
    }

    /**
     * currentVersion() ile aynı versiyon, ilişki olarak: listelerde `with('currentPublishedVersion')`
     * ile prosedür başına ayrı sorgu atmadan yüklenir.
     */
    public function currentPublishedVersion(): HasOne
    {
        return $this->hasOne(ProcedureVersion::class)->ofMany(
            ['version' => 'max'],
            fn (Builder $query) => $query->where('published_at', '<=', now()),
        );
    }

    /**
     * Yayımlanmış ama yayın tarihi henüz gelmemiş en son versiyon.
     */
    public function upcomingVersion(): HasOne
    {
        return $this->hasOne(ProcedureVersion::class)->ofMany(
            ['version' => 'max'],
            fn (Builder $query) => $query->where('published_at', '>', now()),
        );
    }

    /**
     * Düzenlenmekte olan taslak; aynı anda en fazla bir tane olur.
     */
    public function draftVersion(): HasOne
    {
        return $this->hasOne(ProcedureVersion::class)->ofMany(
            ['version' => 'max'],
            fn (Builder $query) => $query->whereNull('published_at'),
        );
    }

    /**
     * Bu prosedürü geçerli prosedür olarak kullanan makineler (K-18).
     */
    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }

    /**
     * Prosedürün herhangi bir versiyonuyla açılmış temizlik kayıtları.
     */
    public function cleanings(): HasManyThrough
    {
        return $this->hasManyThrough(Cleaning::class, ProcedureVersion::class);
    }
}
