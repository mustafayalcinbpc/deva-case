<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * K-06: ilk adımı süresi içinde başlatılmayan kayıt sistem tarafından kapatıldı.
 * Transaction commit edilince yayımlanır.
 */
final readonly class CleaningExpired implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $cleaningId,
        public int $ownerId,
        public int $staleAfterMinutes,
    ) {}
}
