<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CleaningStatus;
use App\Enums\CleaningType;
use App\Enums\PhaseStatus;
use App\Enums\StepStatus;
use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\CleaningMaterial;
use App\Models\CleaningStep;
use App\Models\Facility;
use App\Models\Line;
use App\Models\Machine;
use App\Models\Procedure;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Kayıt açma (Sözleşme 1B `open`): R-14–R-20, K-05, K-14, K-16, K-18, K-19.
 */
class OpenCleaningTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_open_creates_an_unstarted_record_owned_by_the_actor_with_location_from_the_machine(): void
    {
        // R-14, R-15, R-16, R-20, K-18
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');

        $cleaning = $this->workflow()->open($ahmet, $machine, CleaningType::Planned, notes: 'Ürün değişimi');

        $cleaning = $cleaning->fresh();
        $machine->load('line', 'procedure');
        $this->assertSame(CleaningStatus::Created, $cleaning->status);
        $this->assertSame(CleaningType::Planned, $cleaning->type);
        $this->assertEquals($ahmet->id, $cleaning->owner_id);
        $this->assertEquals($machine->line->facility_id, $cleaning->facility_id);
        $this->assertEquals($machine->line_id, $cleaning->line_id);
        $this->assertEquals($machine->id, $cleaning->machine_id);
        $this->assertEquals($machine->procedure->currentVersion()->id, $cleaning->procedure_version_id);
        $this->assertSame('Ürün değişimi', $cleaning->notes);
        $this->assertNull($cleaning->work_order_id);
        $this->assertNull($cleaning->field_ref, 'Saha referansı açılışta değil ilk adımda üretilir (K-17).');
        $this->assertNull($cleaning->started_at, 'Kayıt açmak işe başlamak değildir (R-20).');
        $this->assertNull($cleaning->closed_at);
        $this->assertMoment('2026-10-09 08:00:00', $cleaning->created_at);
    }

    public function test_open_creates_pending_phases_and_steps_with_a_global_step_sequence(): void
    {
        // R-03, R-04: 2 faz (2 + 3 adım) → adımlar fazlar arası 1..5 sıralı.
        $machine = $this->makeMachine([['steps' => 2], ['steps' => 3]]);

        $cleaning = $this->openCleaning($this->operator(), $machine);

        $version = $machine->procedure->currentVersion();
        $phases = $cleaning->phases()->with('procedurePhase')->get();
        $this->assertEquals([1, 2], $phases->pluck('sequence')->all());
        $this->assertEquals($version->phases()->pluck('id')->all(), $phases->pluck('procedure_phase_id')->all());
        $this->assertTrue($phases->every(fn ($phase) => $phase->status === PhaseStatus::Pending));
        $this->assertTrue($phases->every(fn ($phase) => $phase->started_at === null));

        $steps = $cleaning->steps()->with('phase', 'procedureStep')->get();
        $this->assertEquals([1, 2, 3, 4, 5], $steps->pluck('sequence')->all());
        $this->assertEquals([1, 1, 2, 2, 2], $steps->map(fn (CleaningStep $step) => $step->phase->sequence)->all());
        $this->assertEquals([1, 2, 1, 2, 3], $steps->map(fn (CleaningStep $step) => $step->procedureStep->sequence)->all());
        foreach ($steps as $step) {
            $this->assertSame(StepStatus::Pending, $step->status);
            $this->assertNull($step->started_at);
            $this->assertEquals($step->phase->procedure_phase_id, $step->procedureStep->procedure_phase_id);
            $this->assertCount(0, $step->slices);
        }
    }

    public function test_owner_and_helpers_are_assigned_to_every_step_without_duplicates(): void
    {
        // R-22, R-23: sahibi varsayılan görevli; yardımcılar eklenir; tekrarlar tek satır olur.
        $machine = $this->makeMachine([['steps' => 3]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');

        $cleaning = $this->workflow()->open($ahmet, $machine, CleaningType::Planned, [$mehmet->id, $mehmet->id, $ahmet->id]);

        foreach ([1, 2, 3] as $sequence) {
            $step = $this->stepOf($cleaning, $sequence);
            $this->assertSame($this->sortedIds($ahmet, $mehmet), $this->activeAssigneeIds($step));
            $this->assertSame(2, $step->assignees()->count());
        }
    }

    public function test_open_stores_materials_and_records_opened_and_material_added_events(): void
    {
        // R-10, R-16, K-12, R-49
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $detergent = $this->makeMaterial('DET-01');

        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet], [$this->entry($detergent, 'LOT-42', '2027-03-31')]);

        $item = CleaningMaterial::query()->where('cleaning_id', $cleaning->id)->sole();
        $this->assertEquals($detergent->id, $item->material_id);
        $this->assertSame('LOT-42', $item->lot_no);
        $this->assertSame('2027-03-31', $item->expiry_date->format('Y-m-d'));
        $this->assertEquals($ahmet->id, $item->added_by);
        $this->assertNull($item->voided_at);

        $this->assertSame(['cleaning.opened', 'material.added'], $this->eventTypes($cleaning));

        $opened = $this->lastEvent($cleaning, 'cleaning.opened');
        $this->assertEquals($ahmet->id, $opened->actor_id);
        $this->assertMoment('2026-10-09 08:00:00', $opened->occurred_at);
        $this->assertSame($cleaning->record_no, $opened->payload['record_no']);
        $this->assertSame('planned', $opened->payload['type']);
        $this->assertEquals($machine->id, $opened->payload['machine_id']);
        $this->assertEquals($cleaning->procedure_version_id, $opened->payload['procedure_version_id']);
        $this->assertArrayHasKey('work_order_id', $opened->payload);
        $this->assertNull($opened->payload['work_order_id']);
        $this->assertEquals([$mehmet->id], $opened->payload['helper_ids']);

        $added = $this->lastEvent($cleaning, 'material.added');
        $this->assertEquals($ahmet->id, $added->actor_id);
        $this->assertEquals($item->id, $added->payload['cleaning_material_id']);
        $this->assertEquals($detergent->id, $added->payload['material_id']);
        $this->assertSame('LOT-42', $added->payload['lot_no']);
        $this->assertSame('2027-03-31', $added->payload['expiry_date']);

        $this->assertEventChainIntact($cleaning);
    }

    public function test_material_expiring_today_is_accepted(): void
    {
        // K-14: bugün son kullanma tarihi olan malzeme hâlâ geçerlidir.
        $machine = $this->makeMachine();

        $cleaning = $this->openCleaning($this->operator(), $machine, materials: [
            $this->entry($this->makeMaterial(), 'LOT-TODAY', '2026-10-09'),
        ]);

        $this->assertSame(1, $cleaning->materials()->count());
    }

    public function test_expired_material_rejects_the_whole_record(): void
    {
        // K-14: son kullanma tarihi dün → kayıt hiç oluşmaz.
        $machine = $this->makeMachine();
        $material = $this->makeMaterial();

        $e = $this->assertRuleViolation('material_expired', fn () => $this->openCleaning($this->operator(), $machine, materials: [
            $this->entry($material, 'LOT-OK', '2027-01-01'),
            $this->entry($material, 'LOT-OLD', '2026-10-08'),
        ]));

        $this->assertSame('LOT-OLD', $e->context['lot_no']);
        $this->assertSame(0, Cleaning::count());
        $this->assertSame(0, CleaningMaterial::count());
        $this->assertSame(0, CleaningEvent::count());
    }

    public function test_inactive_actor_cannot_open_a_record(): void
    {
        $machine = $this->makeMachine();
        $ahmet = $this->deactivate($this->operator('Ahmet'));

        $this->assertRuleViolation('inactive_user', fn () => $this->openCleaning($ahmet, $machine));

        $this->assertSame(0, Cleaning::count());
    }

    public function test_inactive_helper_cannot_be_added(): void
    {
        $machine = $this->makeMachine();
        $mehmet = $this->deactivate($this->operator('Mehmet'));

        $this->assertRuleViolation('inactive_user', fn () => $this->openCleaning($this->operator('Ahmet'), $machine, [$mehmet]));

        $this->assertSame(0, Cleaning::count());
        $this->assertSame(0, CleaningEvent::count());
    }

    public function test_decommissioned_machine_cannot_be_selected(): void
    {
        // K-16
        $machine = $this->makeMachine();
        $machine->update(['is_active' => false]);

        $this->assertRuleViolation('machine_unavailable', fn () => $this->openCleaning($this->operator(), $machine->fresh()));

        $this->assertSame(0, Cleaning::count());
    }

    public function test_machine_without_a_procedure_cannot_be_cleaned(): void
    {
        // K-18
        $machine = $this->makeMachine();
        $machine->update(['procedure_id' => null]);

        $this->assertRuleViolation('no_procedure', fn () => $this->openCleaning($this->operator(), $machine->fresh()));

        $this->assertSame(0, Cleaning::count());
    }

    public function test_machine_whose_procedure_has_only_an_unpublished_version_cannot_be_cleaned(): void
    {
        // K-15, K-18: yalnızca yayımlanmış versiyon kayda bağlanır.
        $machine = $this->makeMachine();
        $draft = Procedure::create(['code' => 'PRC-DRAFT', 'name' => 'Taslak prosedür']);
        $draft->versions()->create(['version' => 1, 'material_required' => false, 'published_at' => null]);
        $machine->update(['procedure_id' => $draft->id]);

        $this->assertRuleViolation('no_procedure', fn () => $this->openCleaning($this->operator(), $machine->fresh()));

        $this->assertSame(0, Cleaning::count());
    }

    public function test_work_order_of_another_machine_is_rejected(): void
    {
        // K-19
        $machine = $this->makeMachine(code: 'M03');
        $other = $this->makeMachine(code: 'M04');
        $workOrder = WorkOrder::create(['code' => 'WO-1001', 'machine_id' => $other->id]);

        $this->assertRuleViolation('invalid_work_order', fn () => $this->workflow()->open(
            $this->operator(), $machine, CleaningType::Planned, workOrder: $workOrder,
        ));

        $this->assertSame(0, Cleaning::count());
    }

    public function test_work_order_of_another_line_is_rejected(): void
    {
        // K-19
        $machine = $this->makeMachine();
        $otherLine = Line::create([
            'facility_id' => Facility::where('code', 'IST')->value('id'),
            'code' => 'H02',
            'name' => 'Hat 2',
        ]);
        $workOrder = WorkOrder::create(['code' => 'WO-2002', 'line_id' => $otherLine->id]);

        $this->assertRuleViolation('invalid_work_order', fn () => $this->workflow()->open(
            $this->operator(), $machine, CleaningType::Planned, workOrder: $workOrder,
        ));
    }

    public function test_work_order_of_the_machine_is_linked_to_the_record(): void
    {
        // K-19, R-45 (12): hangi üretim işiyle ilişkili.
        $machine = $this->makeMachine();
        $workOrder = WorkOrder::create(['code' => 'WO-3003', 'line_id' => $machine->line_id, 'machine_id' => $machine->id]);

        $cleaning = $this->workflow()->open($this->operator(), $machine, CleaningType::Planned, workOrder: $workOrder);

        $this->assertEquals($workOrder->id, $cleaning->fresh()->work_order_id);
        $this->assertEquals($workOrder->id, $this->lastEvent($cleaning, 'cleaning.opened')->payload['work_order_id']);
    }

    public function test_opening_never_locks_the_machine(): void
    {
        // K-05, R-33: aynı makinede birden fazla başlamamış kayıt olabilir; devam eden
        // bir kayıt varken bile yeni kayıt açılabilir (kilit ilk adımda başlar).
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');

        $first = $this->openCleaning($ahmet, $machine);
        $second = $this->openCleaning($mehmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($first, 1));
        $third = $this->openCleaning($mehmet, $machine);

        $this->assertSame(CleaningStatus::InProgress, $first->fresh()->status);
        $this->assertSame(CleaningStatus::Created, $second->fresh()->status);
        $this->assertSame(CleaningStatus::Created, $third->fresh()->status);
        $this->assertSame(2, Cleaning::pendingOn($machine)->count());
    }

    public function test_the_clock_starts_at_the_first_step_not_at_opening(): void
    {
        // R-20: kayıt 08:00'de açılır, ilk adıma 08:15'te başlanır.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');

        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->travel(15)->minutes();
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $cleaning = $cleaning->fresh();
        $this->assertMoment('2026-10-09 08:00:00', $cleaning->created_at);
        $this->assertMoment('2026-10-09 08:15:00', $cleaning->started_at);
        $this->assertMoment('2026-10-09 08:15:00', $this->phaseOf($cleaning, 1)->started_at);
        $this->assertMoment('2026-10-09 08:15:00', $this->stepOf($cleaning, 1)->started_at);
        $this->assertMoment('2026-10-09 08:15:00', $this->stepOf($cleaning, 1)->slices()->sole()->started_at);
    }

    public function test_machine_with_unpublished_newer_version_uses_the_latest_published_one(): void
    {
        // K-15, K-18: taslak (yayımlanmamış) v2 varken kayıt v1'e bağlanır.
        $machine = $this->makeMachine();
        $v1 = $machine->procedure->currentVersion();
        $machine->procedure->versions()->create(['version' => 2, 'material_required' => false, 'published_at' => null]);

        $cleaning = $this->openCleaning($this->operator(), Machine::find($machine->id));

        $this->assertEquals($v1->id, $cleaning->fresh()->procedure_version_id);
    }
}
