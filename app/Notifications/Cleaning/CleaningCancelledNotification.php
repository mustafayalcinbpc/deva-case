<?php

namespace App\Notifications\Cleaning;

use App\Enums\CancelReason;
use Illuminate\Support\Str;

/**
 * K-08: kaydı başkası (yönetici) iptal etti; kaydın sahibine gider.
 */
class CleaningCancelledNotification extends CleaningNotification
{
    public function __construct(
        int $cleaningId,
        string $recordNo,
        public readonly string $cancelledBy,
        public readonly CancelReason $reason,
        public readonly string $note,
    ) {
        parent::__construct($cleaningId, $recordNo);
    }

    public function databaseType(object $notifiable): string
    {
        return 'cleaning.cancelled';
    }

    public function title(): string
    {
        return 'Kaydınız iptal edildi';
    }

    public function message(): string
    {
        return sprintf(
            '%s kaydı %s tarafından iptal edildi. Gerekçe: %s — %s',
            $this->recordNo,
            $this->cancelledBy,
            $this->reason->label(),
            Str::limit($this->note, 120),
        );
    }

    public function level(): string
    {
        return 'danger';
    }
}
