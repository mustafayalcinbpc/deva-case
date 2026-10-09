<?php

namespace App\Models;

use App\Enums\DefinitionChangeAction;
use App\Models\Concerns\RecordsDefinitionChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['line_id', 'procedure_id', 'code', 'name', 'is_active'])]
class Machine extends Model
{
    use RecordsDefinitionChanges;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function procedure(): BelongsTo
    {
        return $this->belongsTo(Procedure::class);
    }

    /**
     * "IST / H01 / M01"
     */
    public function definitionChangeLabel(): string
    {
        $line = $this->line()->with('facility:id,code')->first();

        return collect([$line?->facility?->code, $line?->code, $this->code])->filter()->implode(' / ');
    }

    /**
     * K-16: kullanımdan kaldırma ve yeniden kullanıma alma (MachineRetirement).
     */
    protected function definitionChangeActions(): array
    {
        return [
            'is_active' => fn ($old, $new) => $new ? DefinitionChangeAction::Reinstated : DefinitionChangeAction::Retired,
        ];
    }

    protected function definitionChangeReferences(): array
    {
        return ['line_id' => Line::class, 'procedure_id' => Procedure::class];
    }
}
