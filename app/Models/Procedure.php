<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name'])]
class Procedure extends Model
{
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
}
