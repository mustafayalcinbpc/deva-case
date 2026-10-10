<?php

namespace Tests\Feature\Planning;

use App\Enums\CleaningPlanKind;
use App\Enums\CleaningTaskSource;
use App\Enums\CleaningTaskStatus;
use App\Enums\CleaningType;
use App\Enums\WorkOrderStatus;
use App\Events\WorkOrderCompleted;
use App\Listeners\OpenCleaningTasksForCompletedWorkOrder;
use App\Models\CleaningPlan;
use App\Models\CleaningTask;
use App\Models\Line;
use App\Models\Machine;
use App\Models\WorkOrder;
use App\Notifications\Planning\CleaningTaskOverdueNotification;
use App\Services\Planning\CleaningTaskGenerator;
use App\Services\Planning\WorkOrderLifecycle;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Planlardan görev üretimi (K-20, K-21) ve geciken görevlerin bildirimi (K-23).
 */
class CleaningTaskGenerationTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    private Machine $m01;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
        $this->m01 = $this->makeMachine(code: 'M01');
    }

    // ---------------------------------------------------------------------------------------
    // Periyodik planlar
    // ---------------------------------------------------------------------------------------

    public function test_new_periodic_plan_opens_its_first_task_at_once(): void
    {
        $plan = $this->periodic($this->m01, 7);

        $this->assertSame(1, $this->generator()->generateDue(now()));

        $task = $plan->tasks()->sole();
        $this->assertSame([CleaningTaskStatus::Open, CleaningTaskSource::Periodic, $this->m01->id], [$task->status, $task->source, $task->machine_id]);
        $this->assertMoment('2026-10-09 08:00:00', $task->due_at);
        $this->assertMoment('2026-10-09 08:00:00', $plan->fresh()->last_task_at);
    }

    public function test_no_new_task_while_the_plan_has_an_active_one(): void
    {
        $plan = $this->periodic($this->m01, 1);
        $this->generator()->generateDue(now());

        $this->at('08:00:00', '2026-10-12');
        $this->assertSame(0, $this->generator()->generateDue(now()));

        // Görevden kayıt açılmış olması da etkin görev sayılır.
        $this->workflow()->open($this->operator(), $this->m01, CleaningType::Planned, task: $plan->tasks()->sole());
        $this->assertSame(0, $this->generator()->generateDue(now()));
        $this->assertSame(1, $plan->tasks()->count());
    }

    public function test_next_task_is_due_one_interval_after_the_last_one(): void
    {
        $plan = $this->periodic($this->m01, 7);
        $this->generator()->generateDue(now());
        $ahmet = $this->operator();
        $this->completeRemainingSteps($ahmet, $this->workflow()->open($ahmet, $this->m01, CleaningType::Planned, task: $plan->tasks()->sole()));

        $this->at('07:59:00', '2026-10-16');
        $this->assertSame(0, $this->generator()->generateDue(now()), 'Aralık dolmadan görev açılmaz.');

        $this->at('09:00:00', '2026-10-16');
        $this->assertSame(1, $this->generator()->generateDue(now()));

        $next = $plan->tasks()->open()->sole();
        $this->assertMoment('2026-10-16 08:00:00', $next->due_at);
        $this->assertMoment('2026-10-16 09:00:00', $plan->fresh()->last_task_at);
    }

    public function test_retired_plan_or_machine_and_trigger_plans_produce_no_periodic_task(): void
    {
        $m02 = $this->makeMachine(code: 'M02');
        $m03 = $this->makeMachine(code: 'M03');
        $this->periodic($this->m01, 7)->update(['is_active' => false]);
        $this->periodic($m02, 7);
        $m02->update(['is_active' => false]);
        CleaningPlan::create(['machine_id' => $m03->id, 'kind' => CleaningPlanKind::WorkOrderCompleted]);

        $this->assertSame(0, $this->generator()->generateDue(now()));
        $this->assertSame(0, CleaningTask::count());
    }

    public function test_command_opens_due_tasks_and_is_scheduled_hourly(): void
    {
        $this->periodic($this->m01, 7);

        $this->artisan('cleaning:generate-tasks')
            ->expectsOutput('Açılan görev: 1')
            ->expectsOutput('Gecikme bildirilen görev: 0')
            ->assertSuccessful();

        $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'cleaning:generate-tasks'));
        $this->assertNotNull($event, 'cleaning:generate-tasks zamanlanmış olmalı.');
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    // ---------------------------------------------------------------------------------------
    // Üretim iş emri tamamlanınca
    // ---------------------------------------------------------------------------------------

    public function test_completed_work_order_opens_a_task_with_the_next_work_order(): void
    {
        $plan = CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::WorkOrderCompleted]);
        $finished = $this->workOrder('IE-1', $this->m01, WorkOrderStatus::InProduction);
        $this->workOrder('IE-5', $this->m01, WorkOrderStatus::Planned, startsInDays: 5);
        $next = $this->workOrder('IE-4', $this->m01, WorkOrderStatus::Planned, startsInDays: 1);
        $this->workOrder('IE-2', null, WorkOrderStatus::Planned, startsInDays: 0, line: $this->m01->line);
        $this->workOrder('IE-3', $this->m01, WorkOrderStatus::Completed);

        $this->at('14:00:00');
        app(WorkOrderLifecycle::class)->complete($finished);

        $task = $plan->tasks()->sole();
        $this->assertSame(CleaningTaskSource::WorkOrder, $task->source);
        $this->assertSame([$finished->id, $next->id], [$task->trigger_work_order_id, $task->work_order_id], 'Makineye bağlı en erken planlanmış emir sonraki emirdir.');
        $this->assertMoment('2026-10-09 14:00:00', $task->due_at);
        $this->assertMoment('2026-10-09 14:00:00', $plan->fresh()->last_task_at);
    }

    public function test_next_work_order_falls_back_to_the_line_then_to_unbound(): void
    {
        CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::WorkOrderCompleted]);
        $finished = $this->workOrder('IE-1', $this->m01, WorkOrderStatus::InProduction);
        $this->workOrder('IE-9', null, WorkOrderStatus::Planned, startsInDays: 0);
        $lineOrder = $this->workOrder('IE-2', null, WorkOrderStatus::Planned, startsInDays: 3, line: $this->m01->line);

        app(WorkOrderLifecycle::class)->complete($finished);

        $this->assertSame($lineOrder->id, CleaningTask::query()->sole()->work_order_id);
    }

    public function test_line_bound_work_order_triggers_plans_of_every_machine_on_the_line(): void
    {
        $m02 = $this->makeMachine(code: 'M02');
        $otherLine = Line::create(['facility_id' => $this->m01->line->facility_id, 'code' => 'H02', 'name' => 'Paketleme']);
        $m05 = $this->makeMachine(code: 'M05');
        $m05->update(['line_id' => $otherLine->id]);
        foreach ([$this->m01, $m02, $m05] as $machine) {
            CleaningPlan::create(['machine_id' => $machine->id, 'kind' => CleaningPlanKind::WorkOrderCompleted]);
        }
        $this->periodic($m02, 7);

        app(WorkOrderLifecycle::class)->complete($this->workOrder('IE-1', null, WorkOrderStatus::InProduction, line: $this->m01->line));

        $this->assertEqualsCanonicalizing([$this->m01->id, $m02->id], CleaningTask::query()->pluck('machine_id')->all());
        $this->assertSame(0, CleaningTask::where('source', CleaningTaskSource::Periodic)->count(), 'Periyodik plan tetikle görev açmaz.');
    }

    public function test_plan_with_an_active_task_or_retired_is_not_triggered_again(): void
    {
        $m02 = $this->makeMachine(code: 'M02');
        $busy = CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::WorkOrderCompleted]);
        $first = CleaningTask::openFor($busy, now()->subDay());
        CleaningPlan::create(['machine_id' => $m02->id, 'kind' => CleaningPlanKind::WorkOrderCompleted, 'is_active' => false]);

        app(WorkOrderLifecycle::class)->complete($this->workOrder('IE-1', null, WorkOrderStatus::InProduction, line: $this->m01->line));

        $this->assertSame([$first->id], CleaningTask::query()->pluck('id')->all());
    }

    public function test_unbound_work_order_triggers_nothing(): void
    {
        CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::WorkOrderCompleted]);

        app(WorkOrderLifecycle::class)->complete($this->workOrder('IE-1', null, WorkOrderStatus::InProduction));

        $this->assertSame(0, CleaningTask::count());
    }

    public function test_listener_is_queued_and_wired_to_the_event(): void
    {
        $this->assertTrue(is_subclass_of(OpenCleaningTasksForCompletedWorkOrder::class, ShouldQueue::class));

        Event::fake();
        Event::assertListening(WorkOrderCompleted::class, OpenCleaningTasksForCompletedWorkOrder::class);
    }

    // ---------------------------------------------------------------------------------------
    // Gecikme bildirimi
    // ---------------------------------------------------------------------------------------

    public function test_overdue_open_task_is_announced_to_active_managers_once(): void
    {
        $zeynep = $this->manager('Zeynep');
        $retired = $this->manager('Eski Yönetici');
        $this->deactivate($retired);
        $operator = $this->operator();
        $plan = $this->periodic($this->m01, 7);
        $task = CleaningTask::openFor($plan, now()->subMinutes(30));
        CleaningTask::openFor($this->periodic($this->makeMachine(code: 'M02'), 7), now()->addHour());

        $this->assertSame(1, $this->generator()->notifyOverdue(now()));
        $this->assertSame(0, $this->generator()->notifyOverdue(now()), 'Aynı görev bir kez bildirilir.');

        $notification = DatabaseNotification::query()->sole();
        $this->assertSame([CleaningTaskOverdueNotification::TYPE, $zeynep->id], [$notification->type, $notification->notifiable_id]);
        $this->assertSame([
            'title' => 'Temizlik gecikti',
            'message' => 'IST / H01 / M01 — Makine M01: yapılması gereken temizliğin son tarihi (09.10.2026 10:30) geçti, kayıt açılmadı.',
            'url' => '/',
            'level' => 'warning',
            'task_id' => $task->id,
        ], $notification->data);
        $this->assertSame(0, $operator->notifications()->count());
    }

    public function test_task_in_record_is_not_overdue(): void
    {
        $this->manager('Zeynep');
        $task = CleaningTask::openFor($this->periodic($this->m01, 7), now()->subHour());
        $this->workflow()->open($this->operator(), $this->m01, CleaningType::Planned, task: $task);

        $this->assertSame(0, $this->generator()->notifyOverdue(now()));
    }

    // ---------------------------------------------------------------------------------------

    private function generator(): CleaningTaskGenerator
    {
        return app(CleaningTaskGenerator::class);
    }

    private function periodic(Machine $machine, int $days): CleaningPlan
    {
        return CleaningPlan::create(['machine_id' => $machine->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => $days]);
    }

    private function workOrder(string $code, ?Machine $machine, WorkOrderStatus $status, int $startsInDays = 0, ?Line $line = null): WorkOrder
    {
        return WorkOrder::create([
            'code' => $code,
            'machine_id' => $machine?->id,
            'line_id' => $machine?->line_id ?? $line?->id,
            'status' => $status,
            'planned_start_at' => now()->addDays($startsInDays),
            'completed_at' => $status === WorkOrderStatus::Completed ? now()->subDay() : null,
        ]);
    }
}
