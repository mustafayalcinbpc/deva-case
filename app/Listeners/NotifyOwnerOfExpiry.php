<?php

namespace App\Listeners;

use App\Events\CleaningExpired;
use App\Models\Cleaning;
use App\Models\User;
use App\Notifications\Cleaning\CleaningExpiredNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * K-06: süresi dolan kayıt sahibine bildirilir (kuyrukta). Kayıt ya da sahip yoksa veya sahip
 * pasifse sessizce biter.
 */
class NotifyOwnerOfExpiry implements ShouldQueue
{
    public function handle(CleaningExpired $event): void
    {
        $cleaning = Cleaning::query()->select(['id', 'record_no'])->find($event->cleaningId);
        $owner = User::query()->whereKey($event->ownerId)->where('is_active', true)->first();

        if ($cleaning === null || $owner === null) {
            return;
        }

        $owner->notify(new CleaningExpiredNotification($cleaning->id, $cleaning->record_no, $event->staleAfterMinutes));
    }
}
