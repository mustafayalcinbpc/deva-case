<?php

namespace Tests\Feature\Planning;

use App\Enums\CleaningPlanKind;
use App\Enums\CleaningTaskStatus;
use App\Enums\CleaningType;
use App\Models\CleaningPlan;
use App\Models\CleaningTask;
use App\Models\DefinitionChange;
use App\Models\Machine;
use App\Models\User;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Temizlik planları yönetimi (K-20): periyodik kural ya da "üretim iş emri tamamlanınca" tetiği.
 * Plan silinmez, kullanımdan kaldırılır; görev üretmiş planın makinesi değişmez.
 */
class CleaningPlanManagementTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    private User $manager;

    private Machine $m01;

    private Machine $m02;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
        $this->manager = $this->manager('Zeynep');
        $this->m01 = $this->makeMachine(code: 'M01');
        $this->m02 = $this->makeMachine(code: 'M02');
    }

    public function test_list_shows_rule_active_task_and_state(): void
    {
        $periodic = CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 7]);
        CleaningTask::openFor($periodic, now()->subHour());
        $trigger = CleaningPlan::create(['machine_id' => $this->m02->id, 'kind' => CleaningPlanKind::WorkOrderCompleted]);
        $next = CleaningPlan::create(['machine_id' => $this->m02->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 14, 'last_task_at' => now()->subDay()]);
        CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::WorkOrderCompleted, 'is_active' => false]);

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.cleaning-plans.index'))
            ->assertOk()
            ->assertSee('<title>Temizlik Planları', false));

        $this->assertSame([
            ['IST / H01 / M01 Makine M01', 'Periyodik · 7 günde bir', 'Gecikti Son tarih: 9 Ekim 10:00', 'Kullanımda'],
            ['IST / H01 / M02 Makine M02', 'Periyodik · 14 günde bir', 'Sonraki görev: 22 Ekim 11:00', 'Kullanımda'],
            ['IST / H01 / M02 Makine M02', 'Üretim iş emri tamamlanınca', 'Üretim iş emri tamamlanınca açılır', 'Kullanımda'],
            ['IST / H01 / M01 Makine M01', 'Üretim iş emri tamamlanınca', '—', 'Kullanımdan kaldırıldı'],
        ], array_map(fn (array $row) => [$row[0], $row[1], $row[2], $row[4]], $this->rows($page)));

        // Menüde görünür.
        $this->assertNotNull($page->querySelector('a[href="'.route('admin.cleaning-plans.index').'"]'));
        $this->assertNotNull($trigger->id);
        $this->assertNotNull($next->id);
    }

    public function test_task_in_record_is_shown_with_its_state(): void
    {
        $plan = CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 7]);
        $task = CleaningTask::openFor($plan, now()->addHour());
        $this->workflow()->open($this->operator(), $this->m01, CleaningType::Planned, task: $task);
        $this->assertSame(CleaningTaskStatus::InRecord, $task->fresh()->status);

        $row = $this->rows($this->page($this->actingAs($this->manager)->get(route('admin.cleaning-plans.index'))))[0];

        $this->assertSame('Kayıt açıldı Son tarih: 9 Ekim 12:00', $row[2]);
    }

    public function test_periodic_plan_is_created_with_an_interval(): void
    {
        $page = $this->page($this->actingAs($this->manager)->get(route('admin.cleaning-plans.create'))->assertOk());
        $this->assertSame(['Seçin', 'M01 — Makine M01', 'M02 — Makine M02'], $this->optionTexts($page, '#machine_id option'));
        $this->assertTrue($page->getElementById('kind-periodic')->hasAttribute('checked'));

        $this->actingAs($this->manager)->post(route('admin.cleaning-plans.store'), [
            'machine_id' => $this->m01->id, 'kind' => 'periodic', 'interval_days' => 7,
        ])->assertRedirect(route('admin.cleaning-plans.index'))->assertSessionHas('status', 'Temizlik planı eklendi: M01 · Periyodik');

        $plan = CleaningPlan::query()->sole();
        $this->assertSame([$this->m01->id, CleaningPlanKind::Periodic, 7, true, null], [$plan->machine_id, $plan->kind, $plan->interval_days, $plan->is_active, $plan->last_task_at]);
    }

    public function test_trigger_plan_has_no_interval(): void
    {
        $this->actingAs($this->manager)->post(route('admin.cleaning-plans.store'), [
            'machine_id' => $this->m01->id, 'kind' => 'work_order_completed', 'interval_days' => 30,
        ])->assertSessionHasNoErrors();

        $this->assertNull(CleaningPlan::query()->sole()->interval_days);
    }

    public function test_validation_messages_are_turkish(): void
    {
        $this->actingAs($this->manager)->from(route('admin.cleaning-plans.create'))->post(route('admin.cleaning-plans.store'), [])
            ->assertSessionHasErrors(['machine_id' => 'makine zorunludur.', 'kind' => 'kural zorunludur.']);

        $this->actingAs($this->manager)->from(route('admin.cleaning-plans.create'))->post(route('admin.cleaning-plans.store'), [
            'machine_id' => $this->m01->id, 'kind' => 'periodic',
        ])->assertSessionHasErrors(['interval_days' => 'Periyodik planda aralık (gün) zorunludur.']);

        $this->actingAs($this->manager)->from(route('admin.cleaning-plans.create'))->post(route('admin.cleaning-plans.store'), [
            'machine_id' => 999999, 'kind' => 'haftalik', 'interval_days' => 0,
        ])->assertSessionHasErrors(['machine_id' => 'Seçilen makine geçersiz.', 'kind' => 'Seçilen kural geçersiz.', 'interval_days']);

        $this->assertSame(0, CleaningPlan::count());
    }

    public function test_retired_machine_cannot_get_a_new_plan(): void
    {
        $this->m02->update(['is_active' => false]);

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.cleaning-plans.create'))->assertOk());
        $this->assertSame(['Seçin', 'M01 — Makine M01'], $this->optionTexts($page, '#machine_id option'));

        $this->actingAs($this->manager)->post(route('admin.cleaning-plans.store'), [
            'machine_id' => $this->m02->id, 'kind' => 'periodic', 'interval_days' => 7,
        ])->assertSessionHasErrors(['machine_id' => 'Seçilen makine geçersiz.']);
    }

    public function test_machine_has_one_active_plan_per_rule(): void
    {
        CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 7]);

        $this->actingAs($this->manager)->post(route('admin.cleaning-plans.store'), [
            'machine_id' => $this->m01->id, 'kind' => 'periodic', 'interval_days' => 3,
        ])->assertSessionHasErrors(['machine_id' => 'Bu makinede aynı kurallı, kullanımda bir temizlik planı var.']);

        // Farklı kural ya da başka makine olur.
        $this->actingAs($this->manager)->post(route('admin.cleaning-plans.store'), [
            'machine_id' => $this->m01->id, 'kind' => 'work_order_completed',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, CleaningPlan::count());
    }

    public function test_plan_without_tasks_can_move_to_another_machine(): void
    {
        $plan = CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 7]);

        $this->actingAs($this->manager)->put(route('admin.cleaning-plans.update', $plan), [
            'machine_id' => $this->m02->id, 'kind' => 'periodic', 'interval_days' => 10,
        ])->assertRedirect(route('admin.cleaning-plans.index'))->assertSessionHasNoErrors();

        $this->assertSame([$this->m02->id, 10], [$plan->fresh()->machine_id, $plan->fresh()->interval_days]);
    }

    public function test_plan_that_produced_tasks_keeps_its_machine(): void
    {
        $plan = CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 7]);
        CleaningTask::openFor($plan, now());

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.cleaning-plans.edit', $plan))->assertOk());
        $this->assertTrue($page->getElementById('machine_id')->hasAttribute('disabled'));
        $this->assertSame((string) $this->m01->id, $page->querySelector('input[type="hidden"][name="machine_id"]')->getAttribute('value'));
        $this->assertStringContainsString('1 görev üretti', $this->text($page->querySelector('.cleaning-plan-form__locked')));

        $this->actingAs($this->manager)->from(route('admin.cleaning-plans.edit', $plan))->put(route('admin.cleaning-plans.update', $plan), [
            'machine_id' => $this->m02->id, 'kind' => 'periodic', 'interval_days' => 7,
        ])->assertSessionHasErrors(['machine_id' => 'Bu plan görev ürettiği için makinesi değiştirilemez; yeni makine için yeni plan ekleyin.']);

        // Kural ve aralık değişebilir.
        $this->actingAs($this->manager)->put(route('admin.cleaning-plans.update', $plan), [
            'machine_id' => $this->m01->id, 'kind' => 'periodic', 'interval_days' => 3,
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, $plan->fresh()->interval_days);
    }

    public function test_plan_is_retired_and_reinstated_and_keeps_its_open_task(): void
    {
        $plan = CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 7]);
        $task = CleaningTask::openFor($plan, now());

        $this->actingAs($this->manager)->post(route('admin.cleaning-plans.deactivate', $plan))
            ->assertRedirect(route('admin.cleaning-plans.index'))
            ->assertSessionHas('status', 'M01 · Periyodik planı kullanımdan kaldırıldı; yeni görev üretmez. Açık görevi varsa olduğu gibi kalır.');
        $this->assertFalse($plan->fresh()->is_active);
        $this->assertSame(CleaningTaskStatus::Open, $task->fresh()->status);

        // Aynı kurallı yeni plan varken eskisi yeniden kullanıma alınamaz.
        $replacement = CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 3]);
        $this->actingAs($this->manager)->post(route('admin.cleaning-plans.activate', $plan))
            ->assertSessionHasErrors(['plan' => 'Bu makinede aynı kurallı, kullanımda bir temizlik planı var.']);

        $replacement->update(['is_active' => false]);
        $this->actingAs($this->manager)->post(route('admin.cleaning-plans.activate', $plan))->assertSessionHasNoErrors();
        $this->assertTrue($plan->fresh()->is_active);
    }

    public function test_changes_are_recorded_in_the_definition_log(): void
    {
        $this->actingAs($this->manager)->post(route('admin.cleaning-plans.store'), [
            'machine_id' => $this->m01->id, 'kind' => 'periodic', 'interval_days' => 7,
        ]);
        $plan = CleaningPlan::query()->sole();
        $this->actingAs($this->manager)->put(route('admin.cleaning-plans.update', $plan), [
            'machine_id' => $this->m01->id, 'kind' => 'periodic', 'interval_days' => 5,
        ]);

        $changes = DefinitionChange::query()->where('subject_type', 'cleaning_plan')->where('subject_id', $plan->id)->orderBy('id')->get();

        $this->assertSame(['created', 'updated'], $changes->map(fn (DefinitionChange $change) => $change->action->value)->all());
        $this->assertSame([7, 5], [$changes[1]->fields['interval_days']['old'], $changes[1]->fields['interval_days']['new']]);

        $page = $this->page($this->actingAs($this->manager)->get(route('admin.cleaning-plans.edit', $plan))->assertOk());
        $this->assertNotNull($page->querySelector('.cleaning-plan-history'));
    }

    public function test_plans_are_for_managers_only(): void
    {
        $plan = CleaningPlan::create(['machine_id' => $this->m01->id, 'kind' => CleaningPlanKind::Periodic, 'interval_days' => 7]);
        $operator = $this->operator();

        $this->actingAs($operator)->get(route('admin.cleaning-plans.index'))->assertForbidden();
        $this->actingAs($operator)->get(route('admin.cleaning-plans.create'))->assertForbidden();
        $this->actingAs($operator)->post(route('admin.cleaning-plans.store'), ['machine_id' => $this->m02->id, 'kind' => 'periodic', 'interval_days' => 1])->assertForbidden();
        $this->actingAs($operator)->put(route('admin.cleaning-plans.update', $plan), ['machine_id' => $this->m01->id, 'kind' => 'periodic', 'interval_days' => 1])->assertForbidden();
        $this->actingAs($operator)->post(route('admin.cleaning-plans.deactivate', $plan))->assertForbidden();

        $this->assertSame([1, 7, true], [CleaningPlan::count(), $plan->fresh()->interval_days, $plan->fresh()->is_active]);
    }

    // ---------------------------------------------------------------------------------------

    /**
     * @return list<list<string>>
     */
    private function rows(HTMLDocument $page): array
    {
        return array_map(
            fn (Element $row) => array_map(fn (Element $cell) => $this->text($cell), iterator_to_array($row->querySelectorAll('td'))),
            iterator_to_array($page->querySelectorAll('tbody tr')),
        );
    }

    /**
     * @return list<string>
     */
    private function optionTexts(HTMLDocument $page, string $selector): array
    {
        return array_map(fn (Element $option) => $this->text($option), iterator_to_array($page->querySelectorAll($selector)));
    }

    private function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    private function text(Element $element): string
    {
        return trim(preg_replace('/\s+/u', ' ', $element->textContent));
    }
}
