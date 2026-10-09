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
     * Yeni kayıtlara uygulanacak versiyon: en son yayımlanmış olan (K-15).
     */
    public function currentVersion(): ?ProcedureVersion
    {
        return $this->versions()->whereNotNull('published_at')->orderByDesc('version')->first();
    }
}
