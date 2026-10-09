<?php

namespace App\Exceptions;

use App\Models\Cleaning;
use App\Models\CleaningPhase;
use App\Models\CleaningStep;
use App\Models\Machine;
use App\Models\User;
use BackedEnum;
use DomainException;

/**
 * Bir iş kuralı ihlali. `rule` makinenin okuyabileceği kısa koddur (arayüz ve
 * testler bunu kullanır), mesaj ise kullanıcıya gösterilecek Türkçe metindir.
 */
final class CleaningRuleViolation extends DomainException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(
        public readonly string $rule,
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidTransition(string $subject, BackedEnum $from, BackedEnum $to): self
    {
        return new self('invalid_transition', "{$subject} '{$from->value}' durumundan '{$to->value}' durumuna geçemez.", [
            'subject' => $subject,
            'from' => $from->value,
            'to' => $to->value,
        ]);
    }

    public static function recordClosed(Cleaning $cleaning): self
    {
        return new self('record_closed', "{$cleaning->record_no} kaydı kapanmış ({$cleaning->status->label()}); üzerinde işlem yapılamaz.");
    }

    public static function notAllowed(string $action): self
    {
        return new self('not_allowed', "Bu işlem için yetkiniz yok: {$action}.", ['action' => $action]);
    }

    public static function machineUnavailable(Machine $machine): self
    {
        return new self('machine_unavailable', "{$machine->code} makinesi kullanımdan kaldırılmış.");
    }

    public static function noProcedure(Machine $machine): self
    {
        return new self('no_procedure', "{$machine->code} makinesinin yayımlanmış geçerli bir prosedürü yok.");
    }

    public static function invalidWorkOrder(): self
    {
        return new self('invalid_work_order', 'Seçilen iş emri bu makineye ait değil.');
    }

    public static function inactiveUser(User $user): self
    {
        return new self('inactive_user', "{$user->name} aktif bir kullanıcı değil.");
    }

    public static function machineBusy(Machine $machine, ?Cleaning $blocking): self
    {
        $suffix = $blocking ? " ({$blocking->record_no})" : '';

        return new self('machine_busy', "{$machine->code} makinesinde devam eden bir temizlik var{$suffix}.", [
            'blocking_cleaning_id' => $blocking?->id,
        ]);
    }

    public static function workerBusy(User $user, ?Cleaning $blocking): self
    {
        $suffix = $blocking ? " ({$blocking->record_no})" : '';

        return new self('worker_busy', "{$user->name} şu anda başka bir adımda çalışıyor{$suffix}.", [
            'user_id' => $user->id,
            'blocking_cleaning_id' => $blocking?->id,
        ]);
    }

    public static function stepOutOfOrder(CleaningStep $step): self
    {
        return new self('step_out_of_order', "{$step->sequence}. adımdan önceki adımlar tamamlanmadan bu adım başlatılamaz.");
    }

    public static function stepCompleted(CleaningStep $step): self
    {
        return new self('step_completed', "{$step->sequence}. adım tamamlanmış; üzerinde değişiklik yapılamaz.");
    }

    public static function noWorkers(): self
    {
        return new self('no_workers', 'Adımda en az bir görevli olmalı.');
    }

    public static function materialRequired(): self
    {
        return new self('material_required', 'Bu prosedürde malzeme zorunlu; malzeme girilmeden ilk adım başlatılamaz.');
    }

    public static function materialExpired(string $lotNo, string $expiryDate): self
    {
        return new self('material_expired', "{$lotNo} lotunun son kullanma tarihi ({$expiryDate}) geçmiş.", [
            'lot_no' => $lotNo,
            'expiry_date' => $expiryDate,
        ]);
    }

    public static function materialAlreadyVoided(): self
    {
        return new self('material_already_voided', 'Bu malzeme zaten geçersiz kılınmış.');
    }

    public static function reasonRequired(): self
    {
        return new self('reason_required', 'Gerekçe yazmak zorunlu.');
    }

    public static function belowMinimumDuration(CleaningPhase $phase, int $measuredSeconds, int $minimumSeconds): self
    {
        return new self(
            'below_minimum_duration',
            "{$phase->sequence}. faz minimum sürenin altında kaldı ({$measuredSeconds} sn < {$minimumSeconds} sn); devam etmek için gerekçe yazın.",
            ['phase_id' => $phase->id, 'measured_seconds' => $measuredSeconds, 'minimum_seconds' => $minimumSeconds],
        );
    }
}
