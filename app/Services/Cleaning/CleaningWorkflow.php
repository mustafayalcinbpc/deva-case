<?php

namespace App\Services\Cleaning;

use App\Enums\CancelReason;
use App\Enums\CleaningStatus;
use App\Enums\CleaningType;
use App\Enums\PhaseStatus;
use App\Enums\SliceEndReason;
use App\Enums\StepStatus;
use App\Exceptions\CleaningRuleViolation;
use App\Models\Cleaning;
use App\Models\CleaningMaterial;
use App\Models\CleaningPhase;
use App\Models\CleaningStep;
use App\Models\Machine;
use App\Models\ProcedureVersion;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Temizlik kaydının durum makinesi. Kayıt, faz, adım ve çalışma dilimi üzerindeki her
 * değişiklik bu sınıftan geçer: kuralları kontrol eder, durumu enum'daki geçiş tablosuyla
 * (`transitionTo`) değiştirir ve olayı değiştirilemez olay zincirine yazar.
 *
 * - Her işlem tek bir transaction'dır; kural ihlalinde hiçbir değişiklik kalıcı olmaz
 *   (CleaningRuleViolation).
 * - İşlem kaydın satırını kilitleyerek başlar, böylece aynı kayıt üzerindeki işlemler sıraya
 *   girer. Zaman kilitten sonra bir kez okunur (R-24, R-47); bir kaydın zamanları kilit
 *   sırasıyla artar.
 * - Makine ve personel kilidinin tek kaynağı veritabanındaki unique index'lerdir (R-35).
 * - Birden fazla ihlal varsa ilki döner:
 *   record_closed → inactive_user → not_allowed → durum geçişi / step_completed →
 *   step_out_of_order → no_workers → material_required → machine_busy → worker_busy
 */
final class CleaningWorkflow
{
    /** K-05: bir makinede en fazla bir "devam ediyor" kayıt (cleanings.active_machine_id). */
    private const MACHINE_LOCK = 'cleanings_active_machine_id_unique';

    /** K-07: bir kişi en fazla bir açık çalışma diliminde (work_slice_workers.active_user_id). */
    private const WORKER_LOCK = 'work_slice_workers_active_user_id_unique';

    public function __construct(
        private readonly RecordNumberGenerator $numbers,
        private readonly CleaningEventRecorder $events,
        private readonly CleaningPermissions $permissions,
    ) {}

    /**
     * Yeni kayıt açar (R-14–R-20). Kayıt açmak işe başlamak değildir ve makineyi kilitlemez
     * (K-05); süre ve makine kilidi ilk adımla başlar.
     *
     * @param  list<int>  $helperIds
     * @param  list<MaterialEntry>  $materials
     */
    public function open(
        User $actor,
        Machine $machine,
        CleaningType $type,
        array $helperIds = [],
        array $materials = [],
        ?WorkOrder $workOrder = null,
        ?string $notes = null,
    ): Cleaning {
        return $this->transaction(function () use ($actor, $machine, $type, $helperIds, $materials, $workOrder, $notes) {
            $now = $this->serverTime();
            $machine->refresh(); // güncel hali: kullanımdan kaldırılmış olabilir (K-16)

            $this->assertActive($actor);

            if (! $machine->is_active) {
                throw CleaningRuleViolation::machineUnavailable($machine); // K-16
            }

            // K-15, K-18: kayıt makinenin prosedürünün en son yayımlanmış versiyonuna bağlanır.
            $version = $machine->procedure?->currentVersion()
                ?? throw CleaningRuleViolation::noProcedure($machine);

            if ($workOrder !== null && ! $workOrder->isUsableFor($machine)) {
                throw CleaningRuleViolation::invalidWorkOrder(); // K-19
            }

            $helperIds = array_values(array_diff($this->uniqueIds($helperIds), [$actor->id]));
            $this->assertUsersActive($helperIds);

            foreach ($materials as $entry) {
                $this->assertNotExpired($entry, $now);
            }

            $cleaning = Cleaning::create([
                'record_no' => $this->numbers->recordNo($machine, $type, $now),
                'type' => $type,
                'status' => CleaningStatus::Created,
                'facility_id' => $machine->line->facility_id,
                'line_id' => $machine->line_id,
                'machine_id' => $machine->id,
                'procedure_version_id' => $version->id,
                'owner_id' => $actor->id,
                'work_order_id' => $workOrder?->id,
                'notes' => $notes,
            ]);

            // R-23: sahibi her adıma varsayılan görevli gelir, yardımcılar da eklenir.
            $this->copyProcedure($cleaning, $version, [$actor->id, ...$helperIds], $actor, $now);

            $this->events->record($cleaning, 'cleaning.opened', $actor, $now, [
                'record_no' => $cleaning->record_no,
                'type' => $type->value,
                'machine_id' => $machine->id,
                'procedure_version_id' => $version->id,
                'work_order_id' => $workOrder?->id,
                'helper_ids' => $helperIds,
            ]);

            foreach ($materials as $entry) {
                $this->recordMaterial($cleaning, $entry, $actor, $now);
            }

            return $cleaning;
        });
    }

    /**
     * Kayda malzeme ekler; temizlik sürerken de eklenebilir (K-12).
     */
    public function addMaterial(User $actor, Cleaning $cleaning, MaterialEntry $entry): CleaningMaterial
    {
        return $this->transaction(function () use ($actor, $cleaning, $entry) {
            $this->lockCleaning($cleaning->id);
            $cleaning->refresh();
            $now = $this->serverTime();

            $this->assertOpen($cleaning);
            $this->assertActive($actor);
            $this->assertCanHandleMaterials($actor, $cleaning, 'malzeme ekleme');
            $this->assertNotExpired($entry, $now);

            return $this->recordMaterial($cleaning, $entry, $actor, $now);
        }, $cleaning);
    }

    /**
     * K-12: yanlış girilen malzeme silinmez; gerekçeyle geçersiz işaretlenir, ilk kayıt kalır.
     */
    public function voidMaterial(User $actor, CleaningMaterial $item, string $reason): void
    {
        $this->transaction(function () use ($actor, $item, $reason) {
            $cleaning = $this->lockCleaning($item->cleaning_id);
            $item->refresh();
            $now = $this->serverTime();

            $this->assertOpen($cleaning);
            $this->assertActive($actor);
            $this->assertCanHandleMaterials($actor, $cleaning, 'malzemeyi geçersiz kılma');

            if ($item->voided_at !== null) {
                throw CleaningRuleViolation::materialAlreadyVoided();
            }

            $reason = $this->requireText($reason);

            $item->update(['voided_at' => $now, 'voided_by' => $actor->id, 'void_reason' => $reason]);

            $this->events->record($cleaning, 'material.voided', $actor, $now, [
                'cleaning_material_id' => $item->id,
                'reason' => $reason,
            ]);
        }, $item);
    }

    /**
     * Adımın görevli listesini değiştirir (R-22, R-23). Adım çalışıyorsa açık dilim kapanır ve
     * aynı anda yeni listeyle yeni dilim açılır; efor dilim bazında hesaplanır (K-04).
     *
     * @param  list<int>  $userIds
     */
    public function setWorkers(User $actor, CleaningStep $step, array $userIds): void
    {
        $this->transaction(function () use ($actor, $step, $userIds) {
            $cleaning = $this->beginStepAction($actor, $step, 'görevlileri değiştirme');
            $now = $this->serverTime();

            if ($step->status === StepStatus::Completed) {
                throw CleaningRuleViolation::stepCompleted($step);
            }

            $userIds = $this->uniqueIds($userIds);

            if ($userIds === []) {
                throw CleaningRuleViolation::noWorkers(); // K-10
            }

            $this->assertUsersActive($userIds);

            $currentIds = $step->activeAssignees()->orderBy('user_id')->pluck('user_id')->all();
            $added = array_values(array_diff($userIds, $currentIds));
            $removed = array_values(array_diff($currentIds, $userIds));

            if ($added === [] && $removed === []) {
                return;
            }

            // Çıkarılan görevlinin satırı silinmez; kimin ne zaman çıkardığı kalır (R-49).
            $step->activeAssignees()
                ->whereIn('user_id', $removed)
                ->update(['removed_at' => $now, 'removed_by' => $actor->id]);

            foreach ($added as $userId) {
                $step->assignees()->create(['user_id' => $userId, 'assigned_by' => $actor->id, 'assigned_at' => $now]);
            }

            if ($step->status === StepStatus::Running) {
                $this->endSlice($step, SliceEndReason::WorkersChanged, $actor, $now);
                $this->startSlice($step, $userIds, $actor, $now);
            }

            $this->events->record($cleaning, 'step.workers_changed', $actor, $now, [
                'step_id' => $step->id,
                'added' => $added,
                'removed' => $removed,
            ]);
        }, $step);
    }

    /**
     * Adımı başlatır. Kaydın ilk adımıysa kayıt "devam ediyor" olur ve makine kilitlenir
     * (R-20, K-05); fazın ilk adımıysa faz başlar.
     */
    public function startStep(User $actor, CleaningStep $step): void
    {
        $this->transaction(function () use ($actor, $step) {
            $cleaning = $this->beginStepAction($actor, $step, 'adımı başlatma');
            $now = $this->serverTime();

            $this->assertStepTransition($step, StepStatus::Pending, StepStatus::Running);
            $this->assertPreviousStepsCompleted($step);
            $workerIds = $this->activeWorkerIds($step);

            if ($cleaning->status === CleaningStatus::Created) {
                $this->startCleaning($cleaning, $actor, $now);
            }

            $this->startPhaseIfPending($cleaning, $step->phase, $actor, $now);

            $step->transitionTo(StepStatus::Running);
            $step->started_at = $now;
            $step->save();

            $this->startSlice($step, $workerIds, $actor, $now);

            $this->events->record($cleaning, 'step.started', $actor, $now, [
                'step_id' => $step->id,
                'sequence' => $step->sequence,
                'worker_ids' => $workerIds,
            ]);
        }, $step);
    }

    /**
     * K-03: adım duraklatılınca açık dilim kapanır ve görevliler serbest kalır. Makine kilidi
     * sürer; makinede yarım kalmış bir temizlik vardır (K-05).
     */
    public function pauseStep(User $actor, CleaningStep $step): void
    {
        $this->transaction(function () use ($actor, $step) {
            $cleaning = $this->beginStepAction($actor, $step, 'adımı duraklatma');
            $now = $this->serverTime();

            $this->assertStepTransition($step, StepStatus::Running, StepStatus::Paused);

            $this->endSlice($step, SliceEndReason::Paused, $actor, $now);
            $step->transitionTo(StepStatus::Paused);
            $step->save();

            $this->events->record($cleaning, 'step.paused', $actor, $now, ['step_id' => $step->id]);
        }, $step);
    }

    /**
     * K-03: duraklatılmış adım yeni bir dilimle devam eder.
     */
    public function resumeStep(User $actor, CleaningStep $step): void
    {
        $this->transaction(function () use ($actor, $step) {
            $cleaning = $this->beginStepAction($actor, $step, 'adımı devam ettirme');
            $now = $this->serverTime();

            $this->assertStepTransition($step, StepStatus::Paused, StepStatus::Running);
            $workerIds = $this->activeWorkerIds($step);

            $step->transitionTo(StepStatus::Running);
            $step->save();

            $this->startSlice($step, $workerIds, $actor, $now);

            $this->events->record($cleaning, 'step.resumed', $actor, $now, [
                'step_id' => $step->id,
                'worker_ids' => $workerIds,
            ]);
        }, $step);
    }

    /**
     * Adımı tamamlar. Fazın son adımıysa faz, son fazın son adımıysa temizlik de tamamlanır
     * (R-25). Faz minimum süreden kısaysa gerekçe zorunludur (K-01).
     */
    public function completeStep(User $actor, CleaningStep $step, ?string $deviationReason = null): void
    {
        $this->transaction(function () use ($actor, $step, $deviationReason) {
            $cleaning = $this->beginStepAction($actor, $step, 'adımı tamamlama');
            $now = $this->serverTime();

            // Duraklatılmış adım doğrudan tamamlanamaz; önce devam ettirilir.
            $this->assertStepTransition($step, StepStatus::Running, StepStatus::Completed);

            $this->endSlice($step, SliceEndReason::Completed, $actor, $now);
            $step->transitionTo(StepStatus::Completed);
            $step->completed_at = $now;
            $step->save();

            $this->events->record($cleaning, 'step.completed', $actor, $now, ['step_id' => $step->id]);

            $phase = $step->phase;

            if ($phase->steps()->where('status', '!=', StepStatus::Completed)->doesntExist()) {
                $this->completePhase($cleaning, $phase, $deviationReason, $actor, $now);

                if ($cleaning->phases()->where('status', '!=', PhaseStatus::Completed)->doesntExist()) {
                    $this->completeCleaning($cleaning, $actor, $now);
                }
            }
        }, $step);
    }

    /**
     * Açık kaydı iptal eder (K-08, K-09). Yapılan adımlar ve dilimler olduğu gibi kalır; açık
     * dilim kapanır, makine ve personel serbest kalır.
     */
    public function cancel(User $actor, Cleaning $cleaning, CancelReason $reason, string $note): void
    {
        $this->transaction(function () use ($actor, $cleaning, $reason, $note) {
            $this->lockCleaning($cleaning->id);
            $cleaning->refresh();
            $now = $this->serverTime();

            $this->assertOpen($cleaning);
            $this->assertActive($actor);
            $this->assertCanCancel($actor, $cleaning, $reason);
            $note = $this->requireText($note);

            $runningStep = $cleaning->steps()->where('status', StepStatus::Running)->first();

            if ($runningStep !== null) {
                $this->endSlice($runningStep, SliceEndReason::Cancelled, $actor, $now);
                $runningStep->transitionTo(StepStatus::Paused);
                $runningStep->save();
            }

            $cleaning->transitionTo(CleaningStatus::Cancelled);
            $cleaning->fill([
                'closed_at' => $now,
                'cancelled_by' => $actor->id,
                'cancel_reason' => $reason,
                'cancel_note' => $note,
            ])->save();

            $this->events->record($cleaning, 'cleaning.cancelled', $actor, $now, [
                'reason' => $reason->value,
                'note' => $note,
            ]);
        }, $cleaning);
    }

    /**
     * K-06: süresi içinde ilk adımı başlatılmayan kayıtları "süresi doldu" durumuna alır.
     * Başlamış kayıtlara dokunulmaz (R-31). Her kayıt ayrı transaction'dadır.
     *
     * @return int süresi dolan kayıt sayısı
     */
    public function expireStale(): int
    {
        $now = $this->serverTime();
        $minutes = (int) config('cleaning.stale_after_minutes');

        $ids = Cleaning::query()
            ->where('status', CleaningStatus::Created)
            ->where('created_at', '<=', $now->subMinutes($minutes))
            ->orderBy('id')
            ->pluck('id');

        $expired = 0;

        foreach ($ids as $id) {
            $expired += $this->transaction(function () use ($id, $now, $minutes) {
                $cleaning = $this->lockCleaning($id);

                // Kilit beklenirken ilk adım başlatılmış ya da kayıt iptal edilmiş olabilir.
                if ($cleaning->status !== CleaningStatus::Created) {
                    return 0;
                }

                $cleaning->transitionTo(CleaningStatus::Expired);
                $cleaning->closed_at = $now;
                $cleaning->save();

                // actor = null: işlemi sistem yaptı.
                $this->events->record($cleaning, 'cleaning.expired', null, $now, ['stale_after_minutes' => $minutes]);

                return 1;
            });
        }

        return $expired;
    }

    // ---------------------------------------------------------------------------------------
    // Geçişlerin parçaları
    // ---------------------------------------------------------------------------------------

    /**
     * İlk adım başlatılırken kayıt "devam ediyor" olur. Makine kilidi active_machine_id unique
     * index'idir; uygulama tarafında ayrıca "makine meşgul mü?" diye bakılmaz (K-05, R-35).
     */
    private function startCleaning(Cleaning $cleaning, User $actor, CarbonImmutable $now): void
    {
        // K-12: prosedür malzeme istiyorsa en az bir geçerli malzeme olmadan başlanamaz.
        if ($cleaning->procedureVersion->material_required && $cleaning->materials()->valid()->doesntExist()) {
            throw CleaningRuleViolation::materialRequired();
        }

        $cleaning->transitionTo(CleaningStatus::InProgress);
        $cleaning->started_at = $now;

        // K-17: saha defteri referansı kayıt açılırken değil ilk adımda üretilir; plansızda yoktur.
        if ($cleaning->type->hasFieldReference()) {
            $cleaning->field_ref = $this->numbers->fieldRef($cleaning->facility, $now);
        }

        try {
            $cleaning->save();
        } catch (UniqueConstraintViolationException $e) {
            if (! $this->hitsLock($e, self::MACHINE_LOCK)) {
                throw $e;
            }

            throw CleaningRuleViolation::machineBusy($cleaning->machine, $this->cleaningHoldingMachine($cleaning->machine_id));
        }

        $this->events->record($cleaning, 'cleaning.started', $actor, $now, ['field_ref' => $cleaning->field_ref]);
    }

    private function startPhaseIfPending(Cleaning $cleaning, CleaningPhase $phase, User $actor, CarbonImmutable $now): void
    {
        if ($phase->status !== PhaseStatus::Pending) {
            return;
        }

        $phase->transitionTo(PhaseStatus::InProgress);
        $phase->started_at = $now;
        $phase->save();

        $this->events->record($cleaning, 'phase.started', $actor, $now, [
            'phase_id' => $phase->id,
            'sequence' => $phase->sequence,
        ]);
    }

    /**
     * K-01, K-02: faz süresi fazın ayarına göre net ya da brüt ölçülür. Minimumun altındaysa
     * faz gerekçeyle kapanır ve sapma olarak işaretlenir; gerekçe yoksa hiçbir değişiklik
     * kalmaz, adım çalışmaya devam eder.
     */
    private function completePhase(Cleaning $cleaning, CleaningPhase $phase, ?string $deviationReason, User $actor, CarbonImmutable $now): void
    {
        $measured = $phase->measuredSeconds();
        $minimum = (int) $phase->procedurePhase->min_duration_seconds;
        $belowMinimum = $measured < $minimum;
        $deviationReason = $belowMinimum ? $this->filledOrNull($deviationReason) : null;

        if ($belowMinimum && $deviationReason === null) {
            throw CleaningRuleViolation::belowMinimumDuration($phase, $measured, $minimum);
        }

        $phase->transitionTo(PhaseStatus::Completed);
        $phase->fill([
            'completed_at' => $now,
            'measured_seconds' => $measured,
            'below_minimum' => $belowMinimum,
            'deviation_reason' => $deviationReason,
        ])->save();

        $this->events->record($cleaning, 'phase.completed', $actor, $now, [
            'phase_id' => $phase->id,
            'sequence' => $phase->sequence,
            'measured_seconds' => $measured,
            'minimum_seconds' => $minimum,
            'below_minimum' => $belowMinimum,
            'deviation_reason' => $deviationReason,
        ]);
    }

    private function completeCleaning(Cleaning $cleaning, User $actor, CarbonImmutable $now): void
    {
        $cleaning->transitionTo(CleaningStatus::Completed);
        $cleaning->closed_at = $now;
        $cleaning->save(); // active_machine_id NULL olur: makine kilidi kalkar (K-05).

        $this->events->record($cleaning, 'cleaning.completed', $actor, $now, [
            'net_seconds' => $cleaning->netSeconds(),
            'gross_seconds' => $cleaning->grossSeconds(),
            'effort_seconds' => $cleaning->effortSeconds(),
        ]);
    }

    /**
     * Yeni çalışma dilimi açar (K-03). Kişi kilidi work_slice_workers.active_user_id unique
     * index'idir (K-07). Görevliler tek tek ve artan id sırasıyla eklenir: çakışan kişi bilinir
     * ve eşzamanlı iki başlatma birbirini karşılıklı beklemez (deadlock).
     *
     * @param  list<int>  $workerIds
     */
    private function startSlice(CleaningStep $step, array $workerIds, User $actor, CarbonImmutable $now): void
    {
        $slice = $step->slices()->create(['started_at' => $now, 'started_by' => $actor->id]);

        foreach ($workerIds as $userId) {
            try {
                $slice->workers()->create(['user_id' => $userId]);
            } catch (UniqueConstraintViolationException $e) {
                if (! $this->hitsLock($e, self::WORKER_LOCK)) {
                    throw $e;
                }

                throw CleaningRuleViolation::workerBusy(User::findOrFail($userId), $this->cleaningWhereWorking($userId));
            }
        }
    }

    /**
     * Açık dilimi kapatır. Görevli satırlarına ended_at yazılınca kişiler serbest kalır (K-07).
     */
    private function endSlice(CleaningStep $step, SliceEndReason $reason, User $actor, CarbonImmutable $now): void
    {
        $slice = $step->openSlice()->firstOrFail();
        $slice->update(['ended_at' => $now, 'ended_by' => $actor->id, 'end_reason' => $reason]);
        $slice->workers()->whereNull('ended_at')->update(['ended_at' => $now]);
    }

    /**
     * Prosedür versiyonunun fazlarını ve adımlarını kayda kopyalar. Adım sırası fazlar arası
     * geneldir (1..N); adım sırası kuralı buna bakar.
     *
     * @param  list<int>  $assigneeIds
     */
    private function copyProcedure(Cleaning $cleaning, ProcedureVersion $version, array $assigneeIds, User $actor, CarbonImmutable $now): void
    {
        $phaseSequence = 0;
        $stepSequence = 0;

        foreach ($version->phases()->with('steps')->get() as $procedurePhase) {
            $phase = $cleaning->phases()->create([
                'procedure_phase_id' => $procedurePhase->id,
                'sequence' => ++$phaseSequence,
                'status' => PhaseStatus::Pending,
            ]);

            foreach ($procedurePhase->steps as $procedureStep) {
                $step = $phase->steps()->create([
                    'cleaning_id' => $cleaning->id,
                    'procedure_step_id' => $procedureStep->id,
                    'sequence' => ++$stepSequence,
                    'status' => StepStatus::Pending,
                ]);

                foreach ($assigneeIds as $userId) {
                    $step->assignees()->create(['user_id' => $userId, 'assigned_by' => $actor->id, 'assigned_at' => $now]);
                }
            }
        }
    }

    private function recordMaterial(Cleaning $cleaning, MaterialEntry $entry, User $actor, CarbonImmutable $now): CleaningMaterial
    {
        $item = $cleaning->materials()->create([
            'material_id' => $entry->materialId,
            'lot_no' => $entry->lotNo,
            'expiry_date' => $entry->expiryDate,
            'added_by' => $actor->id,
        ]);

        $this->events->record($cleaning, 'material.added', $actor, $now, [
            'cleaning_material_id' => $item->id,
            'material_id' => $item->material_id,
            'lot_no' => $item->lot_no,
            'expiry_date' => $item->expiry_date->toDateString(),
        ]);

        return $item;
    }

    // ---------------------------------------------------------------------------------------
    // Kontroller
    // ---------------------------------------------------------------------------------------

    /**
     * Adım işlemlerinin ortak başı: kaydı kilitler, adımı kilitten sonraki haliyle tazeler ve
     * ilk üç kontrolü yapar (record_closed → inactive_user → not_allowed).
     */
    private function beginStepAction(User $actor, CleaningStep $step, string $action): Cleaning
    {
        $cleaning = $this->lockCleaning($step->cleaning_id);
        $step->refresh()->setRelation('cleaning', $cleaning);

        $this->assertOpen($cleaning);
        $this->assertActive($actor);
        $this->assertCanOperateStep($actor, $cleaning, $step, $action);

        return $cleaning;
    }

    /**
     * Kaydın satırını kilitler; aynı kayıt üzerindeki işlemler sıraya girer. Parametre olarak
     * gelen modeller bundan sonra tazelenir ki kilit beklenirken yapılan değişiklikler görülsün.
     */
    private function lockCleaning(int $cleaningId): Cleaning
    {
        return Cleaning::query()->lockForUpdate()->findOrFail($cleaningId);
    }

    private function assertOpen(Cleaning $cleaning): void
    {
        if (! $cleaning->status->isOpen()) {
            throw CleaningRuleViolation::recordClosed($cleaning);
        }
    }

    private function assertActive(User $user): void
    {
        if (! $user->is_active) {
            throw CleaningRuleViolation::inactiveUser($user);
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    private function assertUsersActive(array $userIds): void
    {
        foreach (User::query()->findOrFail($userIds) as $user) {
            $this->assertActive($user);
        }
    }

    // Yetki kuralları CleaningPermissions'ta; ekranlar da aynı sınıfı kullanır.

    private function assertCanOperateStep(User $actor, Cleaning $cleaning, CleaningStep $step, string $action): void
    {
        if (! $this->permissions->canOperateStep($actor, $cleaning, $step)) {
            throw CleaningRuleViolation::notAllowed($action);
        }
    }

    private function assertCanHandleMaterials(User $actor, Cleaning $cleaning, string $action): void
    {
        if (! $this->permissions->canManageMaterials($actor, $cleaning)) {
            throw CleaningRuleViolation::notAllowed($action);
        }
    }

    private function assertCanCancel(User $actor, Cleaning $cleaning, CancelReason $reason): void
    {
        if (! in_array($reason, $this->permissions->allowedCancelReasons($actor, $cleaning), true)) {
            throw CleaningRuleViolation::notAllowed('kaydı iptal etme');
        }
    }

    /**
     * Adım beklenen durumda değilse geçiş geçersizdir. Geçiş tablosu hem Pending hem Paused'dan
     * Running'e izin verdiği için başlatma ile devam ettirme burada ayrılır.
     */
    private function assertStepTransition(CleaningStep $step, StepStatus $from, StepStatus $to): void
    {
        if ($step->status !== $from) {
            throw CleaningRuleViolation::invalidTransition(class_basename($step), $step->status, $to);
        }
    }

    /**
     * Adımlar tanımlı sırayla yürütülür (R-03, R-04): önceki bütün adımlar tamamlanmış olmalı.
     */
    private function assertPreviousStepsCompleted(CleaningStep $step): void
    {
        $unfinished = CleaningStep::query()
            ->where('cleaning_id', $step->cleaning_id)
            ->where('sequence', '<', $step->sequence)
            ->where('status', '!=', StepStatus::Completed)
            ->exists();

        if ($unfinished) {
            throw CleaningRuleViolation::stepOutOfOrder($step);
        }
    }

    /**
     * K-10: her çalışma diliminde en az bir görevli bulunur.
     *
     * @return list<int>
     */
    private function activeWorkerIds(CleaningStep $step): array
    {
        $workerIds = $step->activeAssignees()->orderBy('user_id')->pluck('user_id')->all();

        if ($workerIds === []) {
            throw CleaningRuleViolation::noWorkers();
        }

        return $workerIds;
    }

    private function assertNotExpired(MaterialEntry $entry, CarbonImmutable $now): void
    {
        if ($entry->isExpiredOn($now)) {
            throw CleaningRuleViolation::materialExpired($entry->lotNo, $entry->expiryDate); // K-14
        }
    }

    private function requireText(string $text): string
    {
        return $this->filledOrNull($text) ?? throw CleaningRuleViolation::reasonRequired();
    }

    // ---------------------------------------------------------------------------------------
    // Kilit ihlalleri
    // ---------------------------------------------------------------------------------------

    /**
     * Unique ihlali bizim kilit index'imizden mi geliyor? Başka bir unique ihlali olduğu gibi fırlar.
     */
    private function hitsLock(UniqueConstraintViolationException $e, string $index): bool
    {
        return str_contains($e->getMessage(), $index);
    }

    /**
     * Makineyi tutan "devam ediyor" kayıt. Kilitli okunur: eşzamanlı istekte rakip işlem az önce
     * commit etmiş olabilir ve düz okuma bu transaction'ın eski görüntüsünü döndürür. Unique
     * index üzerinden okunduğu için yalnızca o satır kilitlenir.
     */
    private function cleaningHoldingMachine(int $machineId): ?Cleaning
    {
        return Cleaning::query()->where('active_machine_id', $machineId)->sharedLock()->first();
    }

    /**
     * Kişinin açık diliminin bulunduğu kayıt (kilitli okuma, yukarıdaki gerekçeyle).
     */
    private function cleaningWhereWorking(int $userId): ?Cleaning
    {
        return Cleaning::query()
            ->select('cleanings.*')
            ->join('cleaning_steps', 'cleaning_steps.cleaning_id', '=', 'cleanings.id')
            ->join('work_slices', 'work_slices.cleaning_step_id', '=', 'cleaning_steps.id')
            ->join('work_slice_workers', 'work_slice_workers.work_slice_id', '=', 'work_slices.id')
            ->where('work_slice_workers.active_user_id', $userId)
            ->sharedLock()
            ->first();
    }

    // ---------------------------------------------------------------------------------------
    // Yardımcılar
    // ---------------------------------------------------------------------------------------

    /**
     * Her işlem tek transaction'dır (kilitlenmede 3 kez denenir); ihlalde hiçbir değişiklik
     * kalıcı olmaz. Geri alınan değişiklik bellekteki modelde de kalmasın diye parametre olarak
     * gelen model ihlalden sonra veritabanından yeniden okunur.
     */
    private function transaction(Closure $callback, ?Model $subject = null): mixed
    {
        try {
            return DB::transaction($callback, attempts: 3);
        } catch (CleaningRuleViolation $violation) {
            $subject?->refresh();

            throw $violation;
        }
    }

    /**
     * R-24, R-47: zaman yalnızca sunucudan alınır; bir çağrıdaki bütün zamanlar bu değerdir.
     */
    private function serverTime(): CarbonImmutable
    {
        return now()->toImmutable()->startOfSecond();
    }

    /**
     * @param  array<int|string>  $ids
     * @return list<int>
     */
    private function uniqueIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map(intval(...), $ids)));
        sort($ids);

        return $ids;
    }

    private function filledOrNull(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
