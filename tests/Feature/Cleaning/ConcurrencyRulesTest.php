<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CleaningStatus;
use App\Enums\StepStatus;
use App\Models\WorkSlice;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Makine kilidi (K-05; R-29, R-33, R-34, R-35) ve personel çakışması (K-07; R-30).
 */
class ConcurrencyRulesTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_first_to_start_wins_and_the_other_gets_machine_busy(): void
    {
        // K-05, R-34: iki başlamamış kayıt; ilk başlatan kazanır.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ahmets = $this->openCleaning($ahmet, $machine);
        $mehmets = $this->openCleaning($mehmet, $machine);
        $this->travel(2)->minutes();
        $this->workflow()->startStep($mehmet, $this->stepOf($mehmets, 1));

        $e = $this->assertRuleViolation('machine_busy', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($ahmets, 1)));

        $this->assertEquals($mehmets->id, $e->context['blocking_cleaning_id']);
        $ahmets = $ahmets->fresh();
        $this->assertSame(CleaningStatus::Created, $ahmets->status);
        $this->assertNull($ahmets->started_at);
        $this->assertNull($ahmets->field_ref);
        $this->assertSame(StepStatus::Pending, $this->stepOf($ahmets, 1)->status);
        $this->assertSame(0, $this->stepOf($ahmets, 1)->slices()->count());
        $this->assertSame(['cleaning.opened'], $this->eventTypes($ahmets));
    }

    public function test_waiting_record_can_start_once_the_running_one_is_completed(): void
    {
        // K-05: kilit temizlik tamamlanınca kalkar. Reddedilen başlatma saha defteri
        // sırasında da boşluk bırakmaz (ihlalde hiçbir değişiklik kalıcı olmaz).
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ahmets = $this->openCleaning($ahmet, $machine);
        $mehmets = $this->openCleaning($mehmet, $machine);
        $this->workflow()->startStep($mehmet, $this->stepOf($mehmets, 1));
        $this->assertRuleViolation('machine_busy', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($ahmets, 1)));

        $this->completeRemainingSteps($mehmet, $mehmets->fresh());
        $this->assertSame(CleaningStatus::Completed, $mehmets->fresh()->status);
        $this->workflow()->startStep($ahmet, $this->stepOf($ahmets, 1));

        $this->assertSame(CleaningStatus::InProgress, $ahmets->fresh()->status);
        $this->assertSame('IST-SD-260001', $mehmets->fresh()->field_ref);
        $this->assertSame('IST-SD-260002', $ahmets->fresh()->field_ref);
    }

    public function test_paused_record_keeps_holding_the_machine(): void
    {
        // K-05: makinede yarım kalmış bir temizlik var.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ahmets = $this->openCleaning($ahmet, $machine);
        $mehmets = $this->openCleaning($mehmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($ahmets, 1));
        $this->workflow()->pauseStep($ahmet, $this->stepOf($ahmets, 1));

        $e = $this->assertRuleViolation('machine_busy', fn () => $this->workflow()->startStep($mehmet, $this->stepOf($mehmets, 1)));

        $this->assertEquals($ahmets->id, $e->context['blocking_cleaning_id']);
    }

    public function test_record_between_steps_keeps_holding_the_machine(): void
    {
        // K-05: 1. adım bitti, 2. adım henüz başlamadı → kayıt hâlâ devam ediyor.
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ahmets = $this->openCleaning($ahmet, $machine);
        $mehmets = $this->openCleaning($mehmet, $machine);
        $this->runStep($ahmet, $ahmets, 1);

        $this->assertRuleViolation('machine_busy', fn () => $this->workflow()->startStep($mehmet, $this->stepOf($mehmets, 1)));
    }

    public function test_person_working_in_another_cleaning_cannot_start_their_own_step(): void
    {
        // K-07, R-30: Ahmet, Mehmet'in temizliğinde yardımcı olarak çalışıyor;
        // kendi açtığı temizlikte adım başlatamaz.
        $m01 = $this->makeMachine(code: 'M01');
        $m02 = $this->makeMachine(code: 'M02');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $mehmets = $this->openCleaning($mehmet, $m01, [$ahmet]);
        $ahmets = $this->openCleaning($ahmet, $m02);
        $this->workflow()->startStep($mehmet, $this->stepOf($mehmets, 1));

        $e = $this->assertRuleViolation('worker_busy', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($ahmets, 1)));

        $this->assertEquals($ahmet->id, $e->context['user_id']);
        $this->assertEquals($mehmets->id, $e->context['blocking_cleaning_id']);
        $ahmets = $ahmets->fresh();
        $this->assertSame(CleaningStatus::Created, $ahmets->status, 'Temizlik başlamış sayılmamalı.');
        $this->assertNull($ahmets->field_ref);
        $this->assertSame(StepStatus::Pending, $this->stepOf($ahmets, 1)->status);
        $this->assertSame(0, $this->stepOf($ahmets, 1)->slices()->count());
        $this->assertSame(['cleaning.opened'], $this->eventTypes($ahmets));
    }

    public function test_person_is_released_when_the_step_they_work_on_is_paused(): void
    {
        // K-07, K-03: duraklatınca Ahmet serbest kalır ve kendi adımını başlatabilir.
        $m01 = $this->makeMachine(code: 'M01');
        $m02 = $this->makeMachine(code: 'M02');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $mehmets = $this->openCleaning($mehmet, $m01, [$ahmet]);
        $ahmets = $this->openCleaning($ahmet, $m02);
        $this->workflow()->startStep($mehmet, $this->stepOf($mehmets, 1));
        $this->assertRuleViolation('worker_busy', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($ahmets, 1)));
        $this->travel(5)->minutes();

        $this->workflow()->pauseStep($mehmet, $this->stepOf($mehmets, 1));
        $this->workflow()->startStep($ahmet, $this->stepOf($ahmets, 1));

        $this->assertSame(CleaningStatus::InProgress, $ahmets->fresh()->status);
        $this->assertSame([$ahmet->id], $this->sliceWorkerIds($this->stepOf($ahmets, 1)->slices()->sole()));
    }

    public function test_person_is_released_when_the_step_they_work_on_is_completed(): void
    {
        $m01 = $this->makeMachine(code: 'M01');
        $m02 = $this->makeMachine(code: 'M02');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $mehmets = $this->openCleaning($mehmet, $m01, [$ahmet]);
        $ahmets = $this->openCleaning($ahmet, $m02);

        $this->runStep($mehmet, $mehmets, 1);
        $this->workflow()->startStep($ahmet, $this->stepOf($ahmets, 1));

        $this->assertSame(StepStatus::Running, $this->stepOf($ahmets, 1)->status);
    }

    public function test_own_record_with_a_paused_step_does_not_block_working_elsewhere(): void
    {
        // K-07: kişinin kendi açık kaydı onu başka temizlikte çalışmaktan alıkoymaz.
        $m01 = $this->makeMachine(code: 'M01');
        $m02 = $this->makeMachine(code: 'M02');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ahmets = $this->openCleaning($ahmet, $m01);
        $this->workflow()->startStep($ahmet, $this->stepOf($ahmets, 1));
        $this->workflow()->pauseStep($ahmet, $this->stepOf($ahmets, 1));
        $mehmets = $this->openCleaning($mehmet, $m02, [$ahmet]);

        $this->workflow()->startStep($mehmet, $this->stepOf($mehmets, 1));

        $this->assertSame($this->sortedIds($ahmet, $mehmet), $this->sliceWorkerIds($this->stepOf($mehmets, 1)->slices()->sole()));
    }

    public function test_resuming_with_a_person_busy_elsewhere_is_rejected(): void
    {
        // K-07: devam ettirmek yeni dilim açar; aynı kural geçerli.
        $m01 = $this->makeMachine(code: 'M01');
        $m02 = $this->makeMachine(code: 'M02');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ahmets = $this->openCleaning($ahmet, $m01);
        $this->workflow()->startStep($ahmet, $this->stepOf($ahmets, 1));
        $this->workflow()->pauseStep($ahmet, $this->stepOf($ahmets, 1));
        $mehmets = $this->openCleaning($mehmet, $m02, [$ahmet]);
        $this->workflow()->startStep($mehmet, $this->stepOf($mehmets, 1));

        $e = $this->assertRuleViolation('worker_busy', fn () => $this->workflow()->resumeStep($ahmet, $this->stepOf($ahmets, 1)));

        $this->assertEquals($mehmets->id, $e->context['blocking_cleaning_id']);
        $this->assertSame(StepStatus::Paused, $this->stepOf($ahmets, 1)->status);
        $this->assertSame(1, $this->stepOf($ahmets, 1)->slices()->count());
        $this->assertNotContains('step.resumed', $this->eventTypes($ahmets));
    }

    public function test_adding_a_person_busy_elsewhere_to_a_running_step_changes_nothing(): void
    {
        // K-04, K-07: yeni kişi başka yerde çalışıyorsa worker_busy ve hiçbir değişiklik kalmaz.
        $m01 = $this->makeMachine(code: 'M01');
        $m02 = $this->makeMachine(code: 'M02');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ahmets = $this->openCleaning($ahmet, $m01);
        $mehmets = $this->openCleaning($mehmet, $m02);
        $this->workflow()->startStep($ahmet, $this->stepOf($ahmets, 1));
        $this->workflow()->startStep($mehmet, $this->stepOf($mehmets, 1));
        $this->travel(3)->minutes();

        $e = $this->assertRuleViolation('worker_busy', fn () => $this->workflow()->setWorkers($ahmet, $this->stepOf($ahmets, 1), $this->ids($ahmet, $mehmet)));

        $this->assertEquals($mehmet->id, $e->context['user_id']);
        $this->assertEquals($mehmets->id, $e->context['blocking_cleaning_id']);
        $step = $this->stepOf($ahmets, 1);
        $this->assertSame([$ahmet->id], $this->activeAssigneeIds($step));
        $slice = $step->slices()->sole();
        $this->assertNull($slice->ended_at);
        $this->assertNull($slice->end_reason);
        $this->assertNotContains('step.workers_changed', $this->eventTypes($ahmets));
    }

    public function test_person_busy_elsewhere_can_be_assigned_to_a_step_that_has_not_started(): void
    {
        // K-07: çakışma yalnızca açık çalışma dilimine bakar; başlamamış adımda dilim yoktur.
        $m01 = $this->makeMachine(code: 'M01');
        $m02 = $this->makeMachine(code: 'M02');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $mehmets = $this->openCleaning($mehmet, $m01);
        $this->workflow()->startStep($mehmet, $this->stepOf($mehmets, 1));
        $ahmets = $this->openCleaning($ahmet, $m02);

        $this->workflow()->setWorkers($ahmet, $this->stepOf($ahmets, 1), $this->ids($ahmet, $mehmet));

        $this->assertSame($this->sortedIds($ahmet, $mehmet), $this->activeAssigneeIds($this->stepOf($ahmets, 1)));
    }

    public function test_service_decides_on_the_current_state_not_on_a_stale_model(): void
    {
        // R-34, Sözleşme 1B: model kilitten sonra yenilenir. Ahmet'in ekranındaki adım
        // "pending" görünürken Mehmet adımı başlatmış olsun; Ahmet'in isteği reddedilir.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $staleStep = $this->stepOf($cleaning, 1);
        $this->workflow()->startStep($mehmet, $this->stepOf($cleaning, 1));

        $this->assertRuleViolation('invalid_transition', fn () => $this->workflow()->startStep($ahmet, $staleStep));

        $this->assertSame(1, WorkSlice::count());
        $this->assertSame(1, collect($this->eventTypes($cleaning))->filter(fn ($type) => $type === 'step.started')->count());
    }

    public function test_database_allows_only_one_started_record_per_machine(): void
    {
        // R-35, K-05: servis atlanıp iki kayıt doğrudan in_progress yapılırsa unique index engeller.
        $machine = $this->makeMachine();
        $first = $this->openCleaning($this->operator('Ahmet'), $machine);
        $second = $this->openCleaning($this->operator('Mehmet'), $machine);
        DB::table('cleanings')->where('id', $first->id)->update(['status' => CleaningStatus::InProgress->value]);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('cleanings')->where('id', $second->id)->update(['status' => CleaningStatus::InProgress->value]);
    }

    public function test_database_allows_a_person_in_only_one_open_slice(): void
    {
        // R-35, K-07: servis atlanıp aynı kişi ikinci bir açık dilime yazılırsa unique index engeller.
        $m01 = $this->makeMachine(code: 'M01');
        $m02 = $this->makeMachine(code: 'M02');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $ahmets = $this->openCleaning($ahmet, $m01);
        $mehmets = $this->openCleaning($mehmet, $m02);
        $this->workflow()->startStep($ahmet, $this->stepOf($ahmets, 1));
        $this->workflow()->startStep($mehmet, $this->stepOf($mehmets, 1));
        $mehmetsSlice = $this->stepOf($mehmets, 1)->slices()->sole();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('work_slice_workers')->insert(['work_slice_id' => $mehmetsSlice->id, 'user_id' => $ahmet->id]);
    }
}
