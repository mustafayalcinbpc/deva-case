<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Değiştirilemez olay kaydı. Veritabanında trigger'larla da korunur.
 */
#[Fillable([
    'cleaning_id', 'sequence', 'type', 'actor_id', 'payload', 'occurred_at', 'previous_hash', 'hash',
])]
#[WithoutTimestamps]
class CleaningEvent extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Olay kayıtları değiştirilemez.'));
        static::deleting(fn () => throw new LogicException('Olay kayıtları silinemez.'));
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function cleaning(): BelongsTo
    {
        return $this->belongsTo(Cleaning::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
