<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CancelReason;
use App\Enums\CleaningPlanKind;
use App\Enums\CleaningTaskSource;
use App\Enums\CleaningTaskStatus;
use App\Enums\CleaningType;
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

    public function test_overdue_is_an_open_task_past_its_due_time(): void
    {
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine, dueAt: now()->subHour());

        $this->assertTrue($task->isOverdue(now()));
        $this->assertFalse($task->isOverdue(now()->subHours(2)));
    }

    private function taskFor(Machine $machine, mixed $dueAt = null): CleaningTask
    {
        $plan = CleaningPlan::create(['machine_id' => $machine->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 7]);

        return CleaningTask::openFor($plan, $dueAt ?? now());
    }

    private function openFromTask($owner, Machine $machine, CleaningTask $task, CleaningType $type = CleaningType::Planned)
    {
        return $this->workflow()->open($owner, $machine, $type, task: $task);
    }
}
