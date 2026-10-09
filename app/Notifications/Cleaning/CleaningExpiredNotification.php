<?php

namespace App\Notifications\Cleaning;

/**
 * K-06: kaydın süresi doldu; kaydın sahibine gider.
 */
class CleaningExpiredNotification extends CleaningNotification
{
    public function __construct(
        int $cleaningId,
        string $recordNo,
        public readonly int $staleAfterMinutes,
    ) {
        parent::__construct($cleaningId, $recordNo);
    }

    public function databaseType(object $notifiable): string
    {
        return 'cleaning.expired';
    }

    public function title(): string
    {
        return 'Kaydın süresi doldu';
    }

    public function message(): string
    {
        return "{$this->recordNo}: {$this->staleAfterMinutes} dakika içinde ilk adım başlatılmadığı için kayıt kapatıldı.";
    }

    public function level(): string
    {
        return 'info';
    }
}
