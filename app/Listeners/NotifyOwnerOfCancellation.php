<?php

namespace App\Listeners;

use App\Events\CleaningCancelled;
use App\Models\Cleaning;
use App\Models\User;
use App\Notifications\Cleaning\CleaningCancelledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * K-08: kaydı başkası iptal ettiyse sahibine bildirilir (kuyrukta). Sahibin kendi iptali
 * kuyruğa hiç girmez. Kayıt ya da sahip yoksa veya sahip pasifse sessizce biter.
 */
class NotifyOwnerOfCancellation implements ShouldQueue
{
    public function shouldQueue(CleaningCancelled $event): bool
    {
        return ! $event->cancelledByOwner();
    }

    public function handle(CleaningCancelled $event): void
    {
        if ($event->cancelledByOwner()) {
            return;
        }

        $cleaning = Cleaning::query()->select(['id', 'record_no'])->find($event->cleaningId);
        $owner = User::query()->whereKey($event->ownerId)->where('is_active', true)->first();

        if ($cleaning === null || $owner === null) {
            return;
        }

        $cancelledBy = User::query()->whereKey($event->cancelledById)->value('name') ?? 'başka bir kullanıcı';

        $owner->notify(new CleaningCancelledNotification(
            $cleaning->id,
            $cleaning->record_no,
            $cancelledBy,
            $event->reason,
            $event->note,
        ));
    }
}
