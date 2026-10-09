<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Events\PhaseCompletedBelowMinimum;
use App\Models\CleaningPhase;
use App\Models\User;
use App\Notifications\Cleaning\PhaseBelowMinimumNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * K-01: minimum süre altında kapanan faz bütün aktif yöneticilere bildirilir (kuyrukta).
 * Kayıt ya da faz artık yoksa, aktif yönetici yoksa sessizce biter.
 */
class NotifyManagersOfBelowMinimumPhase implements ShouldQueue
{
    public function handle(PhaseCompletedBelowMinimum $event): void
    {
        $phase = CleaningPhase::query()
            ->with(['cleaning:id,record_no', 'procedurePhase:id,name'])
            ->whereKey($event->phaseId)
            ->where('cleaning_id', $event->cleaningId)
            ->first();

        if ($phase?->cleaning === null) {
            return;
        }

        $managers = User::query()
            ->where('role', UserRole::Manager)
            ->where('is_active', true)
            ->get();

        if ($managers->isEmpty()) {
            return;
        }

        Notification::send($managers, new PhaseBelowMinimumNotification(
            $phase->cleaning->id,
            $phase->cleaning->record_no,
            $phase->procedurePhase?->name ?? "Faz {$phase->sequence}",
            $event->measuredSeconds,
            $event->minimumSeconds,
            $phase->deviation_reason,
        ));
    }
}
