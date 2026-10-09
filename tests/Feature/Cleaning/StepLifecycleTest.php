<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CleaningStatus;
use App\Enums\PhaseStatus;
use App\Enums\SliceEndReason;
use App\Enums\StepStatus;
use App\Models\CleaningStepAssignee;
use App\Models\WorkSlice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Adım yaşam döngüsü (Sözleşme 1B startStep / pauseStep / resumeStep / completeStep /
 * setWorkers): R-21–R-25, K-03, K-04, adım sırası.
 */
class StepLifecycleTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('10:00:00');
    }

    public function test_starting_the_first_step_starts_cleaning_phase_and_step_and_opens_a_slice(): void
    {
        // R-21, R-24, K-03, K-05
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->at('10:05:00');

        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $cleaning = $cleaning->fresh();
        $this->assertSame(CleaningStatus::InProgress, $cleaning->status);
        $this->assertMoment('2026-10-09 10:05:00', $cleaning->started_at);

        $phase = $this->phaseOf($cleaning, 1);
        $this->assertSame(PhaseStatus::InProgress, $phase->status);
        $this->assertMoment('2026-10-09 10:05:00', $phase->started_at);

        $step = $this->stepOf($cleaning, 1);
        $this->assertSame(StepStatus::Running, $step->status);
        $this->assertMoment('2026-10-09 10:05:00', $step->started_at);
        $this->assertNull($step->completed_at);
        $this->assertSame(StepStatus::Pending, $this->stepOf($cleaning, 2)->status);

        $slice = $step->slices()->sole();
        $this->assertMoment('2026-10-09 10:05:00', $slice->started_at);
        $this->assertEquals($ahmet->id, $slice->started_by);
        $this->assertNull($slice->ended_at);
        $this->assertNull($slice->end_reason);
        $this->assertSame($this->sortedIds($ahmet, $mehmet), $this->sliceWorkerIds($slice));

        $started = $this->lastEvent($cleaning, 'step.started');
        $this->assertEquals($step->id, $started->payload['step_id']);
        $this->assertEquals(1, $started->payload['sequence']);
        $this->assertEqualsCanonicalizing($this->ids($ahmet, $mehmet), $started->payload['worker_ids']);

        $phaseStarted = $this->lastEvent($cleaning, 'phase.started');
        $this->assertEquals($phase->id, $phaseStarted->payload['phase_id']);
        $this->assertEquals(1, $phaseStarted->payload['sequence']);
    }

    public function test_pausing_closes_the_slice_and_releases_the_workers(): void
    {
        // K-03: duraklatınca dilim kapanır, görevliler serbest kalır.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:07:00');

        $this->workflow()->pauseStep($mehmet, $this->stepOf($cleaning, 1));

        $step = $this->stepOf($cleaning, 1);
        $this->assertSame(StepStatus::Paused, $step->status);
        $slice = $step->slices()->sole();
        $this->assertMoment('2026-10-09 10:07:00', $slice->ended_at);
        $this->assertSame(SliceEndReason::Paused, $slice->end_reason);
        $this->assertEquals($mehmet->id, $slice->ended_by);
        foreach ($slice->workers as $worker) {
            $this->assertMoment('2026-10-09 10:07:00', $worker->ended_at);
        }
        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status, 'Duraklatma temizlik düzeyinde değildir.');
        $this->assertEquals($step->id, $this->lastEvent($cleaning, 'step.paused')->payload['step_id']);
    }

    public function test_resuming_opens_a_new_slice_and_keeps_the_first_start_time(): void
    {
        // K-03, R-47: adımın started_at'i yalnızca ilk başlatmada set edilir.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:05:00');
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:30:00');

        $this->workflow()->resumeStep($ahmet, $this->stepOf($cleaning, 1));

        $step = $this->stepOf($cleaning, 1);
        $this->assertSame(StepStatus::Running, $step->status);
        $this->assertMoment('2026-10-09 10:00:00', $step->started_at);
        $slices = $step->slices()->get();
        $this->assertCount(2, $slices);
        $this->assertMoment('2026-10-09 10:30:00', $slices[1]->started_at);
        $this->assertNull($slices[1]->ended_at);
        $this->assertSame([$ahmet->id], $this->sliceWorkerIds($slices[1]));

        $resumed = $this->lastEvent($cleaning, 'step.resumed');
        $this->assertEquals($step->id, $resumed->payload['step_id']);
        $this->assertEquals([$ahmet->id], $resumed->payload['worker_ids']);
    }

    public function test_completing_a_step_that_is_not_last_in_its_phase_leaves_the_phase_running(): void
    {
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:04:00');

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));

        $step = $this->stepOf($cleaning, 1);
        $this->assertSame(StepStatus::Completed, $step->status);
        $this->assertMoment('2026-10-09 10:04:00', $step->completed_at);
        $slice = $step->slices()->sole();
        $this->assertMoment('2026-10-09 10:04:00', $slice->ended_at);
        $this->assertSame(SliceEndReason::Completed, $slice->end_reason);
        $this->assertSame(PhaseStatus::InProgress, $this->phaseOf($cleaning, 1)->status);
        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
        $this->assertEquals($step->id, $this->lastEvent($cleaning, 'step.completed')->payload['step_id']);
        $this->assertNotContains('phase.completed', $this->eventTypes($cleaning));
    }

    public function test_completing_the_last_step_of_a_phase_completes_the_phase_and_next_phase_waits_for_its_first_step(): void
    {
        // R-25
        $machine = $this->makeMachine([['steps' => 1], ['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:06:00');

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));

        $first = $this->phaseOf($cleaning, 1);
        $this->assertSame(PhaseStatus::Completed, $first->status);
        $this->assertMoment('2026-10-09 10:06:00', $first->completed_at);
        $this->assertEquals(360, $first->measured_seconds);
        $this->assertFalse($first->below_minimum);
        $this->assertSame(PhaseStatus::Pending, $this->phaseOf($cleaning, 2)->status);
        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);

        $this->at('10:20:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2));

        $second = $this->phaseOf($cleaning, 2);
        $this->assertSame(PhaseStatus::InProgress, $second->status);
        $this->assertMoment('2026-10-09 10:20:00', $second->started_at);
        $this->assertEquals(2, $this->lastEvent($cleaning, 'phase.started')->payload['sequence']);
    }

    public function test_completing_the_last_step_of_the_last_phase_completes_the_cleaning(): void
    {
        // R-25, R-45 (13): ne zaman tamamlanmış.
        $machine = $this->makeMachine([['steps' => 1], ['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->runStep($ahmet, $cleaning, 1, 60);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2));
        $this->at('10:15:00');

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 2));

        $cleaning = $cleaning->fresh();
        $this->assertSame(CleaningStatus::Completed, $cleaning->status);
        $this->assertMoment('2026-10-09 10:15:00', $cleaning->closed_at);
        $this->assertSame(PhaseStatus::Completed, $this->phaseOf($cleaning, 2)->status);
        $this->assertSame(
            ['step.completed', 'phase.completed', 'cleaning.completed'],
            array_slice($this->eventTypes($cleaning), -3),
        );
    }

    public static function invalidTransitions(): iterable
    {
        // [hazırlık: adım 1'in durumu, işlem, adım 1'in değişmeyecek durumu]
        yield 'running adımı tekrar başlatmak' => ['running', 'startStep', StepStatus::Running];
        // Tabloda paused → running var, ama startStep yalnızca pending adımı kabul eder (1B).
        yield 'paused adımı startStep ile başlatmak' => ['paused', 'startStep', StepStatus::Paused];
        yield 'pending adımı duraklatmak' => ['pending', 'pauseStep', StepStatus::Pending];
        yield 'paused adımı tekrar duraklatmak' => ['paused', 'pauseStep', StepStatus::Paused];
        yield 'running adımı devam ettirmek' => ['running', 'resumeStep', StepStatus::Running];
        // Tabloda pending → running var, ama resumeStep yalnızca paused adımı kabul eder (1B).
        yield 'pending adımı devam ettirmek' => ['pending', 'resumeStep', StepStatus::Pending];
        yield 'pending adımı tamamlamak' => ['pending', 'completeStep', StepStatus::Pending];
        yield 'paused adımı tamamlamak (K-03)' => ['paused', 'completeStep', StepStatus::Paused];
        yield 'completed adımı tekrar başlatmak' => ['completed', 'startStep', StepStatus::Completed];
        yield 'completed adımı duraklatmak' => ['completed', 'pauseStep', StepStatus::Completed];
        yield 'completed adımı tekrar tamamlamak' => ['completed', 'completeStep', StepStatus::Completed];
    }

    #[DataProvider('invalidTransitions')]
    public function test_operations_outside_the_step_transition_table_are_rejected(string $state, string $operation, StepStatus $expected): void
    {
        // İki adımlı fazda 1. adım; "completed" durumunda temizlik hâlâ devam ediyor.
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        if ($state !== 'pending') {
            $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
            $this->travel(1)->minutes();
        }
        if ($state === 'paused') {
            $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        }
        if ($state === 'completed') {
            $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));
        }
        $events = count($this->eventTypes($cleaning));
        $slices = WorkSlice::count();
        $this->travel(1)->minutes();

        $this->assertRuleViolation('invalid_transition', fn () => $this->workflow()->{$operation}($ahmet, $this->stepOf($cleaning, 1)));

        $this->assertSame($expected, $this->stepOf($cleaning, 1)->status);
        $this->assertCount($events, $this->eventTypes($cleaning));
        $this->assertSame($slices, WorkSlice::count());
    }

    public static function unfinishedPredecessor(): iterable
    {
        yield '1. adım başlamamış' => ['pending'];
        yield '1. adım çalışıyor' => ['running'];
        yield '1. adım duraklatılmış' => ['paused'];
    }

    #[DataProvider('unfinishedPredecessor')]
    public function test_a_step_cannot_start_before_all_previous_steps_are_completed(string $previous): void
    {
        // R-03, R-04: tanımlı sıra.
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        if ($previous !== 'pending') {
            $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        }
        if ($previous === 'paused') {
            $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        }

        $this->assertRuleViolation('step_out_of_order', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2)));

        $this->assertSame(StepStatus::Pending, $this->stepOf($cleaning, 2)->status);
        $this->assertSame(0, $this->stepOf($cleaning, 2)->slices()->count());
    }

    public function test_first_step_of_next_phase_cannot_start_before_previous_phase_is_completed(): void
    {
        $machine = $this->makeMachine([['steps' => 1], ['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertRuleViolation('step_out_of_order', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2)));

        $this->assertSame(PhaseStatus::Pending, $this->phaseOf($cleaning, 2)->status);
    }

    public function test_a_later_step_cannot_be_started_first(): void
    {
        // R-48: yapılmamış adım atlanıp sonraki yapılmış gibi gösterilemez.
        $machine = $this->makeMachine([['steps' => 3]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);

        $this->assertRuleViolation('step_out_of_order', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 3)));

        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);
        $this->assertSame(['cleaning.opened'], $this->eventTypes($cleaning));
    }

    public function test_step_without_active_workers_cannot_start(): void
    {
        // K-10: her dilimde en az bir görevli.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->removeAllAssigneesDirectly($this->stepOf($cleaning, 1), $ahmet);

        $this->assertRuleViolation('no_workers', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1)));

        $this->assertSame(StepStatus::Pending, $this->stepOf($cleaning, 1)->status);
        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);
    }

    public function test_step_without_active_workers_cannot_resume(): void
    {
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $this->removeAllAssigneesDirectly($this->stepOf($cleaning, 1), $ahmet);

        $this->assertRuleViolation('no_workers', fn () => $this->workflow()->resumeStep($ahmet, $this->stepOf($cleaning, 1)));

        $this->assertSame(StepStatus::Paused, $this->stepOf($cleaning, 1)->status);
        $this->assertSame(1, $this->stepOf($cleaning, 1)->slices()->count());
    }

    public function test_setting_an_empty_worker_list_is_rejected(): void
    {
        // K-10
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertRuleViolation('no_workers', fn () => $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), []));

        $this->assertSame([$ahmet->id], $this->activeAssigneeIds($this->stepOf($cleaning, 1)));
        $this->assertNull($this->stepOf($cleaning, 1)->slices()->sole()->ended_at);
    }

    public function test_inactive_user_cannot_be_assigned_to_a_step(): void
    {
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $ayse = $this->deactivate($this->operator('Ayşe'));
        $cleaning = $this->openCleaning($ahmet, $machine);

        $this->assertRuleViolation('inactive_user', fn () => $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), $this->ids($ahmet, $ayse)));

        $this->assertSame([$ahmet->id], $this->activeAssigneeIds($this->stepOf($cleaning, 1)));
    }

    public function test_workers_of_a_completed_step_cannot_be_changed(): void
    {
        // R-47: tamamlanmış adımın kimlerle yapıldığı sonradan değiştirilemez.
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->runStep($ahmet, $cleaning, 1);

        $this->assertRuleViolation('step_completed', fn () => $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), $this->ids($ahmet, $mehmet)));

        $this->assertSame([$ahmet->id], $this->activeAssigneeIds($this->stepOf($cleaning, 1)));
    }

    public function test_workers_can_be_chosen_before_the_step_starts(): void
    {
        // R-22, R-23: adıma kişi eklenir, ardından adım başlatılır.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine);

        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), $this->ids($ahmet, $mehmet));

        $step = $this->stepOf($cleaning, 1);
        $this->assertSame($this->sortedIds($ahmet, $mehmet), $this->activeAssigneeIds($step));
        $this->assertSame(0, $step->slices()->count(), 'Başlamamış adımda dilim açılmaz.');
        $this->assertSame(StepStatus::Pending, $step->status);
        $changed = $this->lastEvent($cleaning, 'step.workers_changed');
        $this->assertEquals($step->id, $changed->payload['step_id']);
        $this->assertEquals([$mehmet->id], $changed->payload['added']);
        $this->assertEquals([], $changed->payload['removed']);

        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertSame($this->sortedIds($ahmet, $mehmet), $this->sliceWorkerIds($this->stepOf($cleaning, 1)->slices()->sole()));
    }

    public function test_removed_assignee_is_kept_with_removal_time_and_actor(): void
    {
        // K-10, R-47, R-49: çıkarılan görevli satırı silinmez.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->at('10:03:00');

        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), [$ahmet->id]);

        $step = $this->stepOf($cleaning, 1);
        $this->assertSame([$ahmet->id], $this->activeAssigneeIds($step));
        $removed = CleaningStepAssignee::query()
            ->where('cleaning_step_id', $step->id)
            ->where('user_id', $mehmet->id)
            ->sole();
        $this->assertMoment('2026-10-09 10:03:00', $removed->removed_at);
        $this->assertEquals($ahmet->id, $removed->removed_by);
        $changed = $this->lastEvent($cleaning, 'step.workers_changed');
        $this->assertEquals([], $changed->payload['added']);
        $this->assertEquals([$mehmet->id], $changed->payload['removed']);
    }

    public function test_setting_the_same_workers_changes_nothing(): void
    {
        // Sözleşme 1B: fark yoksa hiçbir şey yapılmaz, olay da yazılmaz.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $events = $this->eventTypes($cleaning);
        $this->at('10:05:00');

        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), [$mehmet->id, $ahmet->id]);

        $this->assertSame($events, $this->eventTypes($cleaning));
        $slice = $this->stepOf($cleaning, 1)->slices()->sole();
        $this->assertNull($slice->ended_at);
        $this->assertSame(2, $this->stepOf($cleaning, 1)->assignees()->count());
    }

    public function test_changing_workers_of_a_paused_step_takes_effect_on_resume(): void
    {
        // K-03, K-04: duraklatılmış adımda dilim yoktur; yeni liste devam ederken uygulanır.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:05:00');
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));

        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), [$mehmet->id]);

        $this->assertSame(1, $this->stepOf($cleaning, 1)->slices()->count());
        $this->at('10:20:00');
        $this->workflow()->resumeStep($ahmet, $this->stepOf($cleaning, 1));
        $slices = $this->stepOf($cleaning, 1)->slices()->get();
        $this->assertCount(2, $slices);
        $this->assertSame([$ahmet->id], $this->sliceWorkerIds($slices[0]));
        $this->assertSame([$mehmet->id], $this->sliceWorkerIds($slices[1]));
    }
}
