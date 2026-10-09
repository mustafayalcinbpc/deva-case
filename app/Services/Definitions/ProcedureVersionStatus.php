<?php

namespace App\Services\Definitions;

use App\Models\ProcedureVersion;

/**
 * Prosedür versiyonunun yönetim ekranındaki durumu (K-15). Saklanmaz; yayın tarihinden ve
 * prosedürün geçerli versiyonundan hesaplanır. `<x-status-badge>` ile gösterilir.
 */
enum ProcedureVersionStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Live = 'live';
    case Superseded = 'superseded';

    /**
     * @param  ?int  $currentVersionId  prosedürün geçerli (yayın tarihi gelmiş en son) versiyonu
     */
    public static function of(ProcedureVersion $version, ?int $currentVersionId): self
    {
        return match (true) {
            $version->isDraft() => self::Draft,
            $version->isScheduled() => self::Scheduled,
            $version->id === $currentVersionId => self::Live,
            default => self::Superseded,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Taslak',
            self::Scheduled => 'Yayımlanacak',
            self::Live => 'Yayında',
            self::Superseded => 'Eski',
        };
    }
}
