<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kuyrukta hazırlanan bir rapor dosyası (PDF / CSV). Satır isteğin anında oluşur; dosya
 * kuyruk işi bitince "local" (özel) diske yazılır ve isteyene bildirim gider.
 */
#[Fillable([
    'user_id', 'type', 'parameters', 'status', 'file_path', 'file_name', 'error', 'started_at',
    'completed_at', 'failed_at',
])]
class ReportExport extends Model
{
    public const TYPE_AUDIT_PDF = 'audit_pdf';

    public const TYPE_DURATIONS_CSV = 'durations_csv';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** Dosyaların "local" diskteki klasörü. */
    public const DIRECTORY = 'reports/exports';

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->file_path !== null;
    }

    /**
     * Dosyayı isteyen kişi ya da başka bir yönetici indirebilir (R-43).
     */
    public function isDownloadableBy(User $user): bool
    {
        return $this->user_id === $user->id || $user->isManager();
    }

    /**
     * Bildirimde kullanılan açıklama: hangi raporun, hangi kayıt ya da aralık için olduğu.
     */
    public function description(): string
    {
        return match ($this->type) {
            self::TYPE_AUDIT_PDF => ($this->parameters['record_no'] ?? '').' kaydının denetim raporu (PDF)',
            self::TYPE_DURATIONS_CSV => 'Süre ve efor raporu (CSV), '.($this->parameters['period'] ?? ''),
            default => 'Rapor',
        };
    }

    /**
     * Raporun yeniden istenebileceği sayfa (hata bildiriminde).
     */
    public function sourceUrl(): ?string
    {
        return match ($this->type) {
            self::TYPE_AUDIT_PDF => route('reports.audit', $this->parameters['cleaning_id'], absolute: false),
            self::TYPE_DURATIONS_CSV => route('reports.durations', $this->parameters['filters'] ?? [], absolute: false),
            default => null,
        };
    }
}
