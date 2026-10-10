<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CleaningStatus;
use App\Models\Cleaning;
use App\Models\Machine;
use App\Models\Material;
use App\Services\Cleaning\MaterialEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Lot seçimi ve prosedürün beklediği malzemeler (K-12, K-13, K-14; R-07–R-10).
 */
class MaterialLotsTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_entry_copies_the_lot_number_and_expiry_from_the_lot(): void
    {
        // K-14: operatör lot seçer; satır lotun o anki lot no ve SKT'sini taşır.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $lot = $this->lot($this->makeMaterial(), 'DT-24118', '2027-05-31');

        $cleaning = $this->openCleaning($ahmet, $machine, materials: [new MaterialEntry($lot->id)]);

        $item = $cleaning->materials()->sole();
        $this->assertSame($lot->id, $item->material_lot_id);
        $this->assertSame($lot->material_id, $item->material_id);
        $this->assertSame('DT-24118', $item->lot_no);
        $this->assertSame('2027-05-31', $item->expiry_date->toDateString());
        $this->assertSame($lot->id, $this->lastEvent($cleaning, 'material.added')->payload['material_lot_id']);
    }

    public function test_correcting_the_lot_later_does_not_change_the_record(): void
    {
        // R-13: lot sonradan düzeltilse de kayıttaki kopya değişmez.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $lot = $this->lot($this->makeMaterial(), 'DT-24118', '2027-05-31');
        $cleaning = $this->openCleaning($ahmet, $machine, materials: [new MaterialEntry($lot->id)]);

        $lot->update(['lot_no' => 'DT-24181', 'expiry_date' => '2027-06-30']);

        $item = $cleaning->materials()->sole();
        $this->assertSame('DT-24118', $item->lot_no);
        $this->assertSame('2027-05-31', $item->expiry_date->toDateString());
    }

    public function test_inactive_lot_cannot_be_used(): void
    {
        // K-14: kullanımdan kaldırılan (ör. geri çağrılan) lot seçilemez.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $lot = $this->lot($this->makeMaterial(), 'DT-23001');
        $lot->update(['is_active' => false]);

        $violation = $this->assertRuleViolation('material_lot_unavailable', fn () => $this->openCleaning($ahmet, $machine, materials: [new MaterialEntry($lot->id)]));

        $this->assertSame('DT-23001', $violation->context['lot_no']);
        $this->assertSame(0, Cleaning::query()->where('machine_id', $machine->id)->count());
    }

    public function test_lot_of_a_retired_material_cannot_be_added(): void
    {
        // K-13: kullanımdan kaldırılan malzemenin lotu da eklenemez.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $material = $this->makeMaterial();
        $cleaning = $this->openCleaning($ahmet, $machine);
        $material->update(['is_active' => false]);

        $this->assertRuleViolation('material_lot_unavailable', fn () => $this->workflow()->addMaterial($ahmet, $cleaning->fresh(), $this->entry($material)));

        $this->assertSame(0, $cleaning->materials()->count());
    }

    public function test_every_required_material_of_the_procedure_needs_a_valid_lot(): void
    {
        // K-12, R-09: listedeki her zorunlu malzeme için geçerli giriş olmalı; isteğe bağlı olan gerekmez.
        [$machine, $detergent, $disinfectant, $acid] = $this->machineExpecting(['DET-01' => true, 'DEZ-02' => true, 'DUR-03' => false]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine, materials: [$this->entry($detergent)]);

        $violation = $this->assertRuleViolation('material_required', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1)));
        $this->assertSame(['DEZ-02'], $violation->context['material_codes']);
        $this->assertStringContainsString('DEZ-02', $violation->getMessage());

        $this->workflow()->addMaterial($ahmet, $cleaning->fresh(), $this->entry($disinfectant, 'DZ-11207'));
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
        $this->assertSame(0, $cleaning->materials()->where('material_id', $acid->id)->count());
    }

    public function test_voided_entry_does_not_count_for_a_required_material(): void
    {
        [$machine, $detergent] = $this->machineExpecting(['DET-01' => true]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine, materials: [$this->entry($detergent)]);
        $this->workflow()->voidMaterial($ahmet, $cleaning->materials()->sole(), 'Yanlış lot seçildi');

        $violation = $this->assertRuleViolation('material_required', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1)));

        $this->assertSame(['DET-01'], $violation->context['material_codes']);
    }

    public function test_only_optional_materials_do_not_block_the_first_step(): void
    {
        [$machine] = $this->machineExpecting(['ALK-04' => false]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);

        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
    }

    /**
     * Prosedür versiyonu verilen malzemeleri bekleyen makine (kod => zorunlu mu).
     *
     * @param  array<string, bool>  $expected
     * @return array{0: Machine, ...<int, Material>}
     */
    private function machineExpecting(array $expected): array
    {
        $materials = array_map(fn (string $code) => $this->makeMaterial($code), array_keys($expected));
        $machine = $this->makeMachine(materials: array_map(fn (Material $material) => [$material, $expected[$material->code]], $materials));

        return [$machine, ...$materials];
    }
}
