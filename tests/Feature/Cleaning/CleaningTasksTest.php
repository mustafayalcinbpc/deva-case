<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CancelReason;
use App\Enums\CleaningPlanKind;
use App\Enums\CleaningTaskSource;
use App\Enums\CleaningTaskStatus;
use App\Enums\CleaningType;
use App\Exceptions\CleaningRuleViolation;
use App\Models\Cleaning;
use App\Models\CleaningPlan;
use App\Models\CleaningTask;
use App\Models\Machine;
use App\Models\WorkOrder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Görevden kayıt açma ve görevin durumu (K-20, K-21, K-23). Görev kayıt değildir; kaydı açan
 * kaydın sorumlusudur (R-15).
 */
class CleaningTasksTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_opening_from_a_task_links_the_record_and_marks_the_task(): void
    {
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine);
        $ahmet = $this->operator('Ahmet');

        $cleaning = $this->openFromTask($ahmet, $machine, $task);

        $this->assertSame($task->id, $cleaning->cleaning_task_id);
        $this->assertSame($ahmet->id, $cleaning->owner_id);
        $task = $task->fresh();
        $this->assertSame(CleaningTaskStatus::InRecord, $task->status);
        $this->assertSame($cleaning->id, $task->cleaning_id);
        $this->assertSame($task->cleaning_plan_id, $task->open_plan_id);
        $this->assertSame($task->id, $this->lastEvent($cleaning, 'cleaning.opened')->payload['cleaning_task_id']);
    }

    public function test_a_task_cannot_be_opened_twice(): void
    {
        // K-21: görev satırı kilitlenir; kayıt açılmış görevden ikinci kayıt açılmaz.
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine);
        $this->openFromTask($this->operator('Ahmet'), $machine, $task);

        $this->assertRuleViolation('task_not_open', fn () => $this->openFromTask($this->operator('Mehmet'), $machine, $task));

        $this->assertSame(1, Cleaning::query()->where('machine_id', $machine->id)->count());
    }

    public function test_task_belongs_to_its_machine(): void
    {
        $machine = $this->makeMachine();
        $other = $this->makeMachine(code: 'M05');
        $task = $this->taskFor($machine);

        $this->assertRuleViolation('task_machine_mismatch', fn () => $this->openFromTask($this->operator('Ahmet'), $other, $task));

        $this->assertSame(CleaningTaskStatus::Open, $task->fresh()->status);
    }

    public function test_unplanned_intervention_is_not_opened_from_a_task(): void
    {
        // R-19: plansız müdahale sahada, görevsiz açılır.
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine);

        $this->assertRuleViolation('task_requires_planned', fn () => $this->openFromTask($this->operator('Ahmet'), $machine, $task, CleaningType::Unplanned));

        $this->assertSame(0, Cleaning::query()->where('machine_id', $machine->id)->count());
    }

    public function test_completing_the_record_completes_the_task(): void
    {
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openFromTask($ahmet, $machine, $task);

        $this->completeRemainingSteps($ahmet, $cleaning);

        $task = $task->fresh();
        $this->assertSame(CleaningTaskStatus::Done, $task->status);
        $this->assertNull($task->open_plan_id);
        $this->assertNotNull($task->closed_at);
        $this->assertSame($cleaning->id, $task->cleaning_id);
    }

    public function test_cancelled_record_reopens_the_task(): void
    {
        // K-23: temizlik hâlâ yapılmamıştır; görevden yeni kayıt açılabilir.
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openFromTask($ahmet, $machine, $task);

        $this->workflow()->cancel($ahmet, $cleaning->fresh(), CancelReason::InvalidRecord, 'Yanlış görev seçildi');

        $task = $task->fresh();
        $this->assertSame(CleaningTaskStatus::Open, $task->status);
        $this->assertNull($task->cleaning_id);
        $this->assertSame($task->cleaning_plan_id, $task->open_plan_id);
        $this->assertSame($task->id, $cleaning->fresh()->cleaning_task_id);

        $second = $this->openFromTask($ahmet, $machine, $task);
        $this->assertSame($second->id, $task->fresh()->cleaning_id);
    }

    public function test_expired_record_reopens_the_task(): void
    {
        // K-06, K-23: başlatılmayan kayıt düşer, görev yeniden açık olur.
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine);
        $this->openFromTask($this->operator('Ahmet'), $machine, $task);
        $this->at('08:31:00');

        $this->assertSame(1, $this->workflow()->expireStale());

        $this->assertSame(CleaningTaskStatus::Open, $task->fresh()->status);
        $this->assertNull($task->fresh()->cleaning_id);
    }

    public function test_plan_has_a_single_active_task(): void
    {
        // K-20: open_plan_id unique; ikinci etkin görev veritabanında reddedilir.
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine);

        $this->expectException(UniqueConstraintViolationException::class);

        CleaningTask::openFor($task->plan, now()->addDay());
    }

    public function test_finished_task_frees_the_plan_for_the_next_one(): void
    {
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine);
        $ahmet = $this->operator('Ahmet');
        $this->completeRemainingSteps($ahmet, $this->openFromTask($ahmet, $machine, $task));

        $next = CleaningTask::openFor($task->plan, now()->addDays(7));

        $this->assertSame(CleaningTaskStatus::Open, $next->status);
        $this->assertSame($task->cleaning_plan_id, $next->open_plan_id);
    }

    public function test_task_from_a_completed_work_order_carries_both_work_orders(): void
    {
        $machine = $this->makeMachine();
        $plan = CleaningPlan::create(['machine_id' => $machine->id, 'kind' => CleaningPlanKind::WorkOrderCompleted]);
        $finished = WorkOrder::create(['code' => 'IE-1', 'machine_id' => $machine->id, 'line_id' => $machine->line_id]);
        $next = WorkOrder::create(['code' => 'IE-2', 'machine_id' => $machine->id, 'line_id' => $machine->line_id]);

        $task = CleaningTask::openFor($plan, now(), $finished, $next);

        $this->assertSame(CleaningTaskSource::WorkOrder, $task->source);
        $this->assertSame($finished->id, $task->trigger_work_order_id);
        $this->assertSame($next->id, $task->work_order_id);
    }

    public function test_task_is_upcoming_until_its_time_and_overdue_after_the_tolerance(): void
    {
        // K-24: vakit 09:00, planın toleransı 4 saat: son tarih 13:00.
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine, scheduledAt: now()->addHour());

        $this->assertMoment('2026-10-09 13:00:00', $task->due_at);
        $this->assertTrue($task->isUpcoming(now()));
        $this->assertFalse($task->isDue(now()));
        $this->assertTrue($task->isDue(now()->addHour()));
        $this->assertFalse($task->isOverdue(now()->addHours(5)));
        $this->assertTrue($task->isOverdue(now()->addHours(5)->addSecond()));
    }

    public function test_task_tolerance_comes_from_its_plan(): void
    {
        $machine = $this->makeMachine();
        $plan = CleaningPlan::create(['machine_id' => $machine->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 7, 'tolerance_hours' => 24]);

        $this->assertMoment('2026-10-10 08:00:00', CleaningTask::openFor($plan, now())->due_at);
    }

    public function test_record_is_not_opened_before_the_task_time(): void
    {
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine, scheduledAt: now()->addHours(2));

        $this->assertRuleViolation('task_not_due', fn () => $this->openFromTask($this->operator('Ahmet'), $machine, $task));
        $this->assertSame(CleaningTaskStatus::Open, $task->fresh()->status);

        $this->at('10:00:00');
        $cleaning = $this->openFromTask($this->operator('Ahmet'), $machine, $task);
        $this->assertSame($task->id, $cleaning->cleaning_task_id);
    }

    public function test_task_waiting_for_its_work_order_cannot_be_opened(): void
    {
        $machine = $this->makeMachine();
        $plan = CleaningPlan::create(['machine_id' => $machine->id, 'kind' => CleaningPlanKind::WorkOrderCompleted]);
        $running = WorkOrder::create(['code' => 'IE-7', 'machine_id' => $machine->id, 'line_id' => $machine->line_id, 'status' => 'in_production']);
        $task = CleaningTask::openFor($plan, null, $running);

        $this->assertNull($task->due_at);
        $this->assertTrue($task->isUpcoming(now()->addYear()));
        $this->assertFalse($task->isOverdue(now()->addYear()));

        try {
            $this->openFromTask($this->operator('Ahmet'), $machine, $task);
            $this->fail('Vakti belli olmayan görevden kayıt açılmamalı.');
        } catch (CleaningRuleViolation $violation) {
            $this->assertSame('Bu temizliğin vakti henüz gelmedi; görevden kayıt IE-7 tamamlanınca açılabilir.', $violation->getMessage());
        }
    }

    private function taskFor(Machine $machine, mixed $scheduledAt = null): CleaningTask
    {
        $plan = CleaningPlan::create(['machine_id' => $machine->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 7]);

        return CleaningTask::openFor($plan, $scheduledAt ?? now());
    }

    private function openFromTask($owner, Machine $machine, CleaningTask $task, CleaningType $type = CleaningType::Planned)
    {
        return $this->workflow()->open($owner, $machine, $type, task: $task);
    }
}
