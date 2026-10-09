<?php

namespace App\Services\Cleaning;

use App\Enums\CancelReason;
use App\Enums\CleaningStatus;
use App\Models\Cleaning;
use App\Models\CleaningStep;
use App\Models\User;

/**
 * Kimin neyi yapabileceği. Hem CleaningWorkflow (kuralın uygulandığı yer) hem de ekranlar
 * (hangi butonun gösterileceği) bu sınıfı kullanır; kural tek yerde tanımlıdır.
 * Kaydın açık olması ve kullanıcının aktif olması ayrıca kontrol edilir.
 */
final class CleaningPermissions
{
    /**
     * R-44, K-10, K-11: adımı yalnızca kaydın sahibi ya da o adımın aktif görevlisi yürütür.
     * Görmek çalıştırmak değildir; yönetici rolü burada ayrıcalık vermez.
     */
    public function canOperateStep(User $user, Cleaning $cleaning, CleaningStep $step): bool
    {
        return $cleaning->isOwnedBy($user) || $step->isAssigned($user);
    }

    /**
     * Malzemeyi kaydın sahibi ya da kaydın herhangi bir adımının aktif görevlisi yönetir.
     */
    public function canManageMaterials(User $user, Cleaning $cleaning): bool
    {
        return $cleaning->isOwnedBy($user)
            || $cleaning->steps()
                ->whereHas('activeAssignees', fn ($query) => $query->where('user_id', $user->id))
                ->exists();
    }

    /**
     * K-08, K-09: başlamamış kaydı sahibi ("hatalı kayıt" gerekçesiyle) ya da yönetici,
     * başlamış kaydı yalnızca yönetici iptal eder. Kapalı kayıt için boş liste.
     *
     * @return list<CancelReason>
     */
    public function allowedCancelReasons(User $user, Cleaning $cleaning): array
    {
        return match (true) {
            ! $cleaning->status->isOpen() => [],
            $user->isManager() => CancelReason::cases(),
            $cleaning->status === CleaningStatus::Created && $cleaning->isOwnedBy($user) => [CancelReason::InvalidRecord],
            default => [],
        };
    }
}
