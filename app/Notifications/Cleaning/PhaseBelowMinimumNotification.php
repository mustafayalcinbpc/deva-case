<?php

namespace App\Notifications\Cleaning;

use Illuminate\Support\Str;

/**
 * K-01: faz minimum sürenin altında kapandı; yöneticilere gider.
 */
class PhaseBelowMinimumNotification extends CleaningNotification
{
    public function __construct(
        int $cleaningId,
        string $recordNo,
        public readonly string $phaseName,
        public readonly int $measuredSeconds,
        public readonly int $minimumSeconds,
        public readonly ?string $reason,
    ) {
        parent::__construct($cleaningId, $recordNo);
    }

    public function databaseType(object $notifiable): string
    {
        return 'cleaning.phase-below-minimum';
    }

    public function title(): string
    {
        return 'Minimum süre altında faz';
    }

    public function message(): string
    {
        $message = sprintf(
            '%s: "%s" %s sürdü (minimum %s).',
            $this->recordNo,
            $this->phaseName,
            self::duration($this->measuredSeconds),
            self::duration($this->minimumSeconds),
        );

        return $this->reason === null ? $message : $message.' Gerekçe: '.Str::limit($this->reason, 120);
    }

    public function level(): string
    {
        return 'warning';
    }
}
