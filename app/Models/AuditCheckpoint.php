<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Olay zincirlerinin kontrol noktası (R-46). Yalnızca eklenir; veritabanında trigger'larla da
 * korunur. Alanların anlamı ve özet formülü: App\Services\Cleaning\AuditCheckpoints.
 *
 * @property int $sequence
 * @property int $max_event_id
 * @property int $event_count
 * @property int $cleaning_count
 * @property list<array{0: int, 1: int, 2: string}> $heads
 * @property string $heads_digest
 * @property string|null $previous_digest
 * @property string $digest
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'sequence', 'max_event_id', 'event_count', 'cleaning_count', 'heads', 'heads_digest',
    'previous_digest', 'digest', 'created_at',
])]
#[WithoutTimestamps]
class AuditCheckpoint extends Model
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Kontrol noktaları değiştirilemez.'));
        static::deleting(fn () => throw new LogicException('Kontrol noktaları silinemez.'));
    }

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'max_event_id' => 'integer',
            'event_count' => 'integer',
            'cleaning_count' => 'integer',
            'heads' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
