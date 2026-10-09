<?php

namespace App\Models;

use App\Models\Concerns\RecordsDefinitionChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name'])]
class Facility extends Model
{
    use RecordsDefinitionChanges;

    public function lines(): HasMany
    {
        return $this->hasMany(Line::class);
    }

    public function definitionChangeLabel(): string
    {
        return (string) $this->code;
    }
}
