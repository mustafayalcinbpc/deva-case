<?php

namespace Tests\Feature\Cleaning\Concerns;

use App\Enums\CleaningType;
use App\Enums\StepStatus;
use App\Exceptions\CleaningRuleViolation;
use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\CleaningPhase;
use App\Models\CleaningStep;
use App\Models\Machine;
use App\Models\Material;
use App\Models\User;
use App\Models\WorkSlice;
use App\Services\Cleaning\CleaningEventRecorder;
use App\Services\Cleaning\CleaningWorkflow;
use App\Services\Cleaning\MaterialEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * CleaningWorkflow davranış testleri için kısa yardımcılar. Senaryoyu gizlememek için
 * yalnızca tekrar eden çağrıları sarar; kurulum her testin içinde açıkça yapılır.
 */
trait InteractsWithCleaningWorkflow
{
    protected function workflow(): CleaningWorkflow
    {
        return app(CleaningWorkflow::class);
    }

    /**
     * Sunucu saatini verilen ana sabitler (tam saniye).
     */
    protected function at(string $time, string $date = '2026-10-09'): CarbonImmutable
    {
        $moment = CarbonImmutable::parse("{$date} {$time}");
        $this->travelTo($moment);

        return $moment;
    }

    /**
     * @param  list<User>  $helpers
     * @param  list<MaterialEntry>  $materials
     */
    protected function openCleaning(
        User $owner,
        Machine $machine,
        array $helpers = [],
        array $materials = [],
        CleaningType $type = CleaningType::Planned,
    ): Cleaning {
        return $this->workflow()->open($owner, $machine, $type, $this->ids(...$helpers), $materials);
    }

    protected function entry(Material $material, string $lotNo = 'LOT-001', string $expiryDate = '2027-12-31'): MaterialEntry
    {
        return new MaterialEntry($material->id, $lotNo, $expiryDate);
    }

    /**
     * Adımı her seferinde veritabanından taze okur (fazlar arası genel sıra ile).
     */
    protected function stepOf(Cleaning $cleaning, int $sequence): CleaningStep
    {
        return CleaningStep::query()
            ->where('cleaning_id', $cleaning->id)
            ->where('sequence', $sequence)
            ->firstOrFail();
    }

    protected function phaseOf(Cleaning $cleaning, int $sequence): CleaningPhase
    {
        return CleaningPhase::query()
            ->where('cleaning_id', $cleaning->id)
            ->where('sequence', $sequence)
            ->firstOrFail();
    }

    /**
     * Adımı başlatır, verilen süre çalıştırır ve kapatır.
     */
    protected function runStep(User $actor, Cleaning $cleaning, int $sequence, int $seconds = 60, ?string $deviationReason = null): void
    {
        $this->workflow()->startStep($actor, $this->stepOf($cleaning, $sequence));
        $this->travel($seconds)->seconds();
        $this->workflow()->completeStep($actor, $this->stepOf($cleaning, $sequence), $deviationReason);
    }

    /**
     * Kalan bütün adımları sırayla çalıştırıp temizliği tamamlar.
     */
    protected function completeRemainingSteps(User $actor, Cleaning $cleaning, int $secondsPerStep = 60): void
    {
        $pending = CleaningStep::query()
            ->where('cleaning_id', $cleaning->id)
            ->where('status', '!=', StepStatus::Completed->value)
            ->orderBy('sequence')
            ->get();

        foreach ($pending as $step) {
            if ($step->status === StepStatus::Pending) {
                $this->workflow()->startStep($actor, $this->stepOf($cleaning, $step->sequence));
            } elseif ($step->status === StepStatus::Paused) {
                $this->workflow()->resumeStep($actor, $this->stepOf($cleaning, $step->sequence));
            }

            $this->travel($secondsPerStep)->seconds();
            $this->workflow()->completeStep($actor, $this->stepOf($cleaning, $step->sequence));
        }
    }

    /**
     * İşlemin belirtilen kural koduyla reddedildiğini doğrular ve istisnayı döndürür.
     */
    protected function assertRuleViolation(string $rule, callable $action): CleaningRuleViolation
    {
        try {
            $action();
        } catch (CleaningRuleViolation $e) {
            $this->assertSame($rule, $e->rule, "'{$rule}' bekleniyordu, '{$e->rule}' geldi: {$e->getMessage()}");

            return $e;
        }

        $this->fail("'{$rule}' kural ihlali bekleniyordu, işlem başarıyla tamamlandı.");
    }

    /**
     * @return list<string>
     */
    protected function eventTypes(Cleaning $cleaning): array
    {
        return CleaningEvent::query()
            ->where('cleaning_id', $cleaning->id)
            ->orderBy('sequence')
            ->pluck('type')
            ->all();
    }

    /**
     * Verilen tipteki en son olay.
     */
    protected function lastEvent(Cleaning $cleaning, string $type): CleaningEvent
    {
        return CleaningEvent::query()
            ->where('cleaning_id', $cleaning->id)
            ->where('type', $type)
            ->orderByDesc('sequence')
            ->firstOrFail();
    }

    protected function assertEventChainIntact(Cleaning $cleaning): void
    {
        $this->assertTrue(
            app(CleaningEventRecorder::class)->verify($cleaning),
            'Olay zinciri doğrulanamadı.',
        );
    }

    /**
     * @return list<int>
     */
    protected function ids(User ...$users): array
    {
        return array_map(fn (User $user) => $user->id, $users);
    }

    /**
     * Adımın açık (çıkarılmamış) görevlileri, sıralı.
     *
     * @return list<int>
     */
    protected function activeAssigneeIds(CleaningStep $step): array
    {
        return $step->activeAssignees()->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Dilimde çalışan kişiler, sıralı.
     *
     * @return list<int>
     */
    protected function sliceWorkerIds(WorkSlice $slice): array
    {
        return $slice->workers()->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return list<int>
     */
    protected function sortedIds(User ...$users): array
    {
        $ids = $this->ids(...$users);
        sort($ids);

        return $ids;
    }

    protected function assertMoment(string $expected, ?CarbonInterface $actual, string $message = ''): void
    {
        $this->assertNotNull($actual, $message ?: "{$expected} zamanı bekleniyordu, NULL geldi.");
        $this->assertSame($expected, $actual->format('Y-m-d H:i:s'), $message);
    }

    protected function deactivate(User $user): User
    {
        $user->forceFill(['is_active' => false])->save();

        return $user;
    }

    /**
     * Servisin izin vermediği "görevsiz adım" durumunu veritabanında doğrudan kurar
     * (setWorkers boş listeyi zaten reddeder).
     */
    protected function removeAllAssigneesDirectly(CleaningStep $step, User $by): void
    {
        $step->assignees()->whereNull('removed_at')->update([
            'removed_at' => now(),
            'removed_by' => $by->id,
        ]);
    }
}
