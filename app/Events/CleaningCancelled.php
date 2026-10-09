<?php

namespace App\Events;

use App\Enums\CancelReason;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Açık kayıt iptal edildi (K-08, K-09): kim, hangi gerekçeyle. Transaction commit edilince yayımlanır.
 */
final readonly class CleaningCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $cleaningId,
        public int $ownerId,
        public int $cancelledById,
        public CancelReason $reason,
        public string $note,
    ) {}

    public function cancelledByOwner(): bool
    {
        return $this->cancelledById === $this->ownerId;
    }
}
