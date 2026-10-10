<?php

namespace Tests\Feature\Screens;

use App\Enums\CancelReason;
use App\Enums\CleaningPlanKind;
use App\Enums\CleaningTaskStatus;
use App\Enums\CleaningType;
use App\Models\Cleaning;
use App\Models\CleaningPlan;
use App\Models\CleaningTask;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use Carbon\CarbonInterface;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Yapılması gereken temizlikler (K-21, K-23): gösterge panelindeki açık görevler, görevden kayıt
 * açma formu, yöneticinin görevi iptali ve kayıtta görev bilgisi. Görevler henüz atanmamıştır;
 * herhangi bir aktif operatör görevden kayıt açabilir ve kaydı açan sorumludur (R-15).
 */
class CleaningTaskScreensTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    // ---------------------------------------------------------------------------------------
    // Gösterge paneli
    // ---------------------------------------------------------------------------------------

    public function test_dashboard_lists_open_tasks_by_due_time_and_marks_overdue_ones(): void
    {
        $filler = $this->makeMachine(code: 'M02');
        $tank = $this->makeMachine(code: 'M01');
        $labeler = $this->makeMachine(code: 'M05');
        $later = $this->taskFor($filler, now()->addDays(3));
        $overdue = $this->taskFor($tank, now()->subHours(2));
        $done = $this->taskFor($labeler, now()->subDay());
        $ahmet = $this->operator('Ahmet');
        $this->completeRemainingSteps($ahmet, $this->workflow()->open($ahmet, $labeler, CleaningType::Planned, task: $done));

        $response = $this->actingAs($this->operator('Mehmet'))->get(route('dashboard'))->assertOk();

        $rows = $this->page($response)->querySelectorAll('.due-tasks__row');
        $this->assertSame(["task-{$overdue->id}", "task-{$later->id}"], array_map(fn (Element $row) => $row->id, iterator_to_array($rows)));
        $this->assertSame('2', $this->text($this->one($response, '.due-tasks__count')));

        $this->assertStringContainsString('Gecikti', $this->text($this->one($response, "#task-{$overdue->id}")));
        $this->assertNotNull($this->one($response, "#task-{$overdue->id}")->querySelector('.status-badge--overdue'));
        $this->assertNull($this->one($response, "#task-{$later->id}")->querySelector('.status-badge--overdue'));
        $this->assertStringContainsString('Periyodik plan', $this->text($this->one($response, "#task-{$later->id}")));
        $this->assertSame(
            route('cleanings.create', ['task' => $overdue->id]),
            $this->one($response, "#task-{$overdue->id} .due-tasks__open")->getAttribute('href'),
        );
    }

    public function test_task_row_shows_the_triggering_and_the_next_work_order(): void
    {
        $machine = $this->makeMachine();
        [$finished, $next] = $this->workOrders($machine);
        $plan = CleaningPlan::create(['machine_id' => $machine->id, 'kind' => CleaningPlanKind::WorkOrderCompleted]);
        $task = CleaningTask::openFor($plan, now(), $finished, $next);

        $response = $this->actingAs($this->operator())->get(route('dashboard'))->assertOk();

        $row = $this->text($this->one($response, "#task-{$task->id}"));
        $this->assertStringContainsString('Üretim iş emri tamamlandı IE-1041', $row);
        $this->assertStringContainsString('IE-1055 Çinko şurup 100 ml', $row);
    }

    public function test_dashboard_says_when_there_is_nothing_to_do(): void
    {
        $response = $this->actingAs($this->operator())->get(route('dashboard'))->assertOk();

        $this->assertStringContainsString('Şu anda yapılması gereken temizlik yok.', $this->text($this->one($response, '.due-tasks')));
        $this->assertNull($this->page($response)->querySelector('.due-tasks__table'));
    }

    public function test_only_the_manager_sees_the_cancel_form(): void
    {
        $task = $this->taskFor($this->makeMachine(), now());

        $operatorPage = $this->actingAs($this->operator())->get(route('dashboard'))->assertOk();
        $managerPage = $this->actingAs($this->manager())->get(route('dashboard'))->assertOk();

        $this->assertNull($this->page($operatorPage)->querySelector('.due-tasks__cancel'));
        $this->assertSame(
            route('tasks.cancel', $task),
            $this->one($managerPage, "#task-{$task->id} .due-tasks__cancel form")->getAttribute('action'),
        );
    }

    // ---------------------------------------------------------------------------------------
    // Görev iptali
    // ---------------------------------------------------------------------------------------

    public function test_manager_cancels_an_open_task_with_a_reason_and_frees_the_plan(): void
    {
        $task = $this->taskFor($this->makeMachine(), now());
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post(route('tasks.cancel', $task), ['cancel_reason' => 'Makine planlı bakımda; temizlik bakım sonrası yapılacak.'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'M03 makinesinin görevi iptal edildi.');

        $task = $task->fresh();
        $this->assertSame(CleaningTaskStatus::Cancelled, $task->status);
        $this->assertNull($task->open_plan_id);
        $this->assertNotNull($task->closed_at);
        $this->assertSame($manager->id, $task->cancelled_by);
        $this->assertSame('Makine planlı bakımda; temizlik bakım sonrası yapılacak.', $task->cancel_reason);

        // Planın etkin görevi boşaldı: sıradaki görev üretilebilir.
        $this->assertSame(CleaningTaskStatus::Open, CleaningTask::openFor($task->plan, now()->addWeek())->status);
    }

    public function test_cancel_requires_a_reason_and_reopens_the_form_on_that_row(): void
    {
        $task = $this->taskFor($this->makeMachine(), now());
        $manager = $this->manager();

        $this->actingAs($manager)
            ->from(route('dashboard'))
            ->post(route('tasks.cancel', $task), ['cancel_reason' => '   ', 'cancel_task_id' => $task->id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors(['cancel_reason' => 'gerekçe zorunludur.']);

        $this->assertSame(CleaningTaskStatus::Open, $task->fresh()->status);

        $response = $this->actingAs($manager)->withSession(['_old_input' => ['cancel_task_id' => $task->id]])->get(route('dashboard'));
        $this->assertTrue($this->one($response, "#task-{$task->id} .due-tasks__cancel")->hasAttribute('open'));
    }

    public function test_operator_cannot_cancel_a_task(): void
    {
        $task = $this->taskFor($this->makeMachine(), now());

        $this->actingAs($this->operator())
            ->post(route('tasks.cancel', $task), ['cancel_reason' => 'Gerek yok'])
            ->assertForbidden();

        $this->assertSame(CleaningTaskStatus::Open, $task->fresh()->status);
    }

    public function test_task_with_an_open_record_is_not_cancelled(): void
    {
        // K-23: kayıt açılmış görev iptal edilmez; önce kayıt iptal edilir.
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine, now());
        $this->workflow()->open($this->operator(), $machine, CleaningType::Planned, task: $task);

        $this->actingAs($this->manager())
            ->from(route('dashboard'))
            ->post(route('tasks.cancel', $task), ['cancel_reason' => 'Vazgeçildi'])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHasErrors(['task' => 'Görev artık açık değil; iptal edilemez.']);

        $this->assertSame(CleaningTaskStatus::InRecord, $task->fresh()->status);
    }

    // ---------------------------------------------------------------------------------------
    // Görevden kayıt açma
    // ---------------------------------------------------------------------------------------

    public function test_create_form_from_a_task_locks_machine_and_type_and_prefills_the_work_order(): void
    {
        $machine = $this->makeMachine(code: 'M02');
        $this->makeMachine(code: 'M05');
        [$finished, $next] = $this->workOrders($machine);
        $plan = CleaningPlan::create(['machine_id' => $machine->id, 'kind' => CleaningPlanKind::WorkOrderCompleted]);
        $task = CleaningTask::openFor($plan, now()->subHour(), $finished, $next);

        $response = $this->actingAs($this->operator())->get(route('cleanings.create', ['task' => $task->id]))->assertOk();

        $this->assertSame((string) $task->id, $this->one($response, 'input[type="hidden"][name="cleaning_task_id"]')->getAttribute('value'));
        $this->assertSame((string) $machine->id, $this->one($response, 'input[type="hidden"][name="machine_id"]')->getAttribute('value'));
        $select = $this->one($response, '#machine_id');
        $this->assertTrue($select->hasAttribute('disabled'));
        $this->assertFalse($select->hasAttribute('name'));
        $this->assertSame((string) $machine->id, $select->querySelector('option[selected]')->getAttribute('value'));

        $this->assertTrue($this->one($response, '#type-planned')->hasAttribute('checked'));
        $this->assertTrue($this->one($response, '#type-unplanned')->hasAttribute('disabled'));
        $this->assertSame((string) $next->id, $this->one($response, '#work_order_id option[selected]')->getAttribute('value'));

        $summary = $this->text($this->one($response, '.cleaning-form__task'));
        $this->assertStringContainsString('M02 — Makine M02', $summary);
        $this->assertStringContainsString('Üretim iş emri tamamlandı · IE-1041', $summary);
        $this->assertStringContainsString('IE-1055', $summary);
        $this->assertStringContainsString('Gecikti', $summary);
    }

    public function test_create_form_without_a_task_is_unchanged(): void
    {
        $this->makeMachine();

        $response = $this->actingAs($this->operator())->get(route('cleanings.create'))->assertOk();

        $this->assertNull($this->page($response)->querySelector('input[name="cleaning_task_id"]'));
        $this->assertNull($this->page($response)->querySelector('.cleaning-form__task, .cleaning-form__task-gone'));
        $select = $this->one($response, '#machine_id');
        $this->assertSame('machine_id', $select->getAttribute('name'));
        $this->assertFalse($select->hasAttribute('disabled'));
        $this->assertFalse($this->one($response, '#type-unplanned')->hasAttribute('disabled'));
    }

    public function test_create_form_warns_when_the_task_is_no_longer_open(): void
    {
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine, now());
        $this->workflow()->open($this->operator('Ahmet'), $machine, CleaningType::Planned, task: $task);

        $response = $this->actingAs($this->operator('Mehmet'))->get(route('cleanings.create', ['task' => $task->id]))->assertOk();

        $this->assertStringContainsString('Bu görev artık açık değil', $this->text($this->one($response, '.cleaning-form__task-gone')));
        $this->assertNull($this->page($response)->querySelector('input[name="cleaning_task_id"]'));
    }

    public function test_store_opens_the_record_from_the_task_with_the_opener_as_owner(): void
    {
        $machine = $this->makeMachine();
        [, $next] = $this->workOrders($machine);
        $task = $this->taskFor($machine, now());
        $ayse = $this->operator('Ayşe');

        $response = $this->actingAs($ayse)->post(route('cleanings.store'), [
            'cleaning_task_id' => $task->id,
            'machine_id' => $machine->id,
            'type' => CleaningType::Planned->value,
            'work_order_id' => $next->id,
        ]);

        $cleaning = Cleaning::sole();
        $response->assertRedirect(route('cleanings.show', $cleaning));
        $this->assertSame($ayse->id, $cleaning->owner_id);
        $this->assertSame($task->id, $cleaning->cleaning_task_id);
        $this->assertSame(CleaningTaskStatus::InRecord, $task->fresh()->status);
        $this->assertNotContains($task->id, CleaningTask::open()->pluck('id')->all());
    }

    public function test_store_from_a_task_that_was_taken_meanwhile_comes_back_with_the_workflow_error(): void
    {
        // K-21: iki kişi aynı görevden kayıt açmaya çalışırsa ikincisi reddedilir.
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine, now());
        $this->workflow()->open($this->operator('Ahmet'), $machine, CleaningType::Planned, task: $task);
        $url = route('cleanings.create', ['task' => $task->id]);

        $this->actingAs($this->operator('Mehmet'))
            ->from($url)
            ->post(route('cleanings.store'), [
                'cleaning_task_id' => $task->id,
                'machine_id' => $machine->id,
                'type' => CleaningType::Planned->value,
            ])
            ->assertRedirect($url)
            ->assertSessionHasErrors(['workflow' => 'Bu görev için kayıt açılamaz; görev açık değil.']);

        $this->assertSame(1, Cleaning::count());
    }

    // ---------------------------------------------------------------------------------------
    // Kayıtta görev
    // ---------------------------------------------------------------------------------------

    public function test_record_detail_shows_the_task_in_summary_and_history(): void
    {
        $machine = $this->makeMachine();
        $task = $this->taskFor($machine, now()->addHours(6));
        $ahmet = $this->operator('Ahmet');
        $fromTask = $this->workflow()->open($ahmet, $machine, CleaningType::Planned, task: $task);
        $this->workflow()->cancel($ahmet, $fromTask->fresh(), CancelReason::InvalidRecord, 'Yanlış makine seçildi');
        $withoutTask = $this->workflow()->open($ahmet, $machine, CleaningType::Planned);

        $response = $this->actingAs($ahmet)->get(route('cleanings.show', $fromTask))->assertOk();

        $this->assertSame('Görev Periyodik plan son tarih 09.10.2026 17:00', $this->text($this->one($response, '#summary .cleaning-summary__task')));
        $this->assertStringContainsString('Görev Periyodik plan, son tarih 09.10.2026 17:00', $this->text($this->one($response, '#history .event-history__item--cleaning-opened')));

        $other = $this->actingAs($ahmet)->get(route('cleanings.show', $withoutTask))->assertOk();
        $this->assertSame('Görev Görevsiz açıldı', $this->text($this->one($other, '#summary .cleaning-summary__task')));
        $this->assertStringNotContainsString('Görev', $this->text($this->one($other, '#history .event-history__item--cleaning-opened .event-history__details')));
    }

    // ---------------------------------------------------------------------------------------

    private function taskFor(Machine $machine, CarbonInterface $dueAt): CleaningTask
    {
        $plan = CleaningPlan::create(['machine_id' => $machine->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 7]);

        return CleaningTask::openFor($plan, $dueAt);
    }

    /**
     * Makinede tamamlanmış ve sıradaki üretim iş emri.
     *
     * @return array{0: WorkOrder, 1: WorkOrder}
     */
    private function workOrders(Machine $machine): array
    {
        return [
            WorkOrder::create(['code' => 'IE-1041', 'line_id' => $machine->line_id, 'machine_id' => $machine->id, 'product' => 'Parasetamol şurup 150 ml', 'status' => 'completed']),
            WorkOrder::create(['code' => 'IE-1055', 'line_id' => $machine->line_id, 'machine_id' => $machine->id, 'product' => 'Çinko şurup 100 ml']),
        ];
    }

    private function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    private function one(TestResponse $response, string $selector): Element
    {
        $element = $this->page($response)->querySelector($selector);
        $this->assertNotNull($element, "Sayfada '{$selector}' yok.");

        return $element;
    }

    private function text(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }
}
