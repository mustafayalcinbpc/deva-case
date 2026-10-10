<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * K-19, K-20: üretim iş emri tamamlandı (gerçekte ERP bildirir). "Üretim iş emri tamamlanınca"
 * kurallı temizlik planları görev açar. Transaction commit edilince yayımlanır.
 */
final readonly class WorkOrderCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public int $workOrderId,
    ) {}
}
