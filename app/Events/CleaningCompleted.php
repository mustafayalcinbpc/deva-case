<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Son fazın son adımı kapandı, temizlik tamamlandı (R-25). Transaction commit edilince yayımlanır.
 */
final readonly class CleaningCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $cleaningId,
        public int $ownerId,
        public int $actorId,
    ) {}
}
