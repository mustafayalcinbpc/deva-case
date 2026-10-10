<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use App\Models\Concerns\RecordsDefinitionChanges;
use App\Models\Concerns\TransitionsStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Üretim iş emri (K-19). Bir makineye, bir hatta ya da hiçbirine bağlı değildir; makineye bağlı
 * üretim iş emri o makinenin hattına da bağlıdır. Durumu ve zamanları gerçekte ERP'den gelir;
 * "tamamlandı"ya geçiş temizlik planlarının tetiğidir (K-20).
 */
#[Fillable([
    'code', 'line_id', 'machine_id', 'description', 'product', 'status', 'planned_start_at',
    'planned_end_at', 'completed_at',
])]
class WorkOrder extends Model
{
    use RecordsDefinitionChanges, TransitionsStatus;

    protected $attributes = [
        'status' => 'planned',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'planned_start_at' => 'immutable_datetime',
            'planned_end_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function definitionChangeLabel(): string
    {
        return (string) $this->code;
    }

    protected function definitionChangeReferences(): array
    {
        return ['line_id' => Line::class, 'machine_id' => Machine::class];
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(Line::class);
    }

    public function cleanings(): HasMany
    {
        return $this->hasMany(Cleaning::class);
    }

    /**
     * Üretim iş emri makineye ya da hatta bağlıysa, yalnızca o makinedeki temizliklerde kullanılabilir.
     */
    public function isUsableFor(Machine $machine): bool
    {
        return ($this->machine_id === null || $this->machine_id === $machine->id)
            && ($this->line_id === null || $this->line_id === $machine->line_id);
    }

    /**
     * Bağlı olduğu hat: doğrudan hat ya da makinenin hattı (eski verilerde makineye bağlı iş
     * emrinin line_id'si boş olabilir). machine.line ve line ilişkileri yüklenmiş olmalıdır.
     */
    public function boundLine(): ?Line
    {
        return $this->line ?? $this->machine?->line;
    }

    /**
     * "IST / H01 / M01" biçiminde konum; bağlı değilse null. machine.line.facility ve
     * line.facility ilişkileri yüklenmiş olmalıdır.
     */
    public function locationCodes(): ?string
    {
        $line = $this->boundLine();
        $location = collect([$line?->facility?->code, $line?->code, $this->machine?->code])->filter()->implode(' / ');

        return $location === '' ? null : $location;
    }
}
