<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * K-01: faz minimum süreden kısa sürdü ve gerekçeyle kapandı. Olaylar model değil kimlik taşır;
 * dinleyici güncel veriyi kendisi okur. Transaction commit edilince yayımlanır.
 */
final readonly class PhaseCompletedBelowMinimum implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $cleaningId,
        public int $phaseId,
        public int $actorId,
        public int $measuredSeconds,
        public int $minimumSeconds,
    ) {}
}
