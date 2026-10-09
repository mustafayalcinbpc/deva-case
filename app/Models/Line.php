<?php

namespace App\Models;

use App\Models\Concerns\RecordsDefinitionChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['facility_id', 'code', 'name'])]
class Line extends Model
{
    use RecordsDefinitionChanges;

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }

    /**
     * "IST / H01"
     */
    public function definitionChangeLabel(): string
    {
        return collect([$this->facility()->value('code'), $this->code])->filter()->implode(' / ');
    }

    protected function definitionChangeReferences(): array
    {
        return ['facility_id' => Facility::class];
    }
}
