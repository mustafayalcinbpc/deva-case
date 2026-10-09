<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CleaningStatus;
use App\Enums\PhaseStatus;
use App\Models\Machine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Devam ederken prosedürün değişmesi (K-15; R-11, R-13).
 */
class ProcedureVersioningTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_open_record_stays_on_the_version_it_was_opened_with(): void
    {
        // K-15: v1 ile açılan kayıt, v2 yayımlansa da v1 ile devam eder; yeni kayıt v2 alır.
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $v1 = $machine->procedure->currentVersion();
        $old = $this->openCleaning($ahmet, $machine);

        $v2 = $this->publishVersion($machine->procedure, [['steps' => 3], ['steps' => 1]]);
        $this->workflow()->startStep($ahmet, $this->stepOf($old, 1));
        $new = $this->openCleaning($mehmet, Machine::find($machine->id));

        $this->assertEquals($v1->id, $old->fresh()->procedure_version_id);
        $this->assertSame(1, $old->phases()->count());
        $this->assertSame(2, $old->steps()->count());
        $this->assertEquals($v2->id, $new->fresh()->procedure_version_id);
        $this->assertSame(2, $new->phases()->count());
        $this->assertSame(4, $new->steps()->count());
        $this->assertEquals(
            $v2->phases()->pluck('id')->all(),
            $new->phases()->pluck('procedure_phase_id')->all(),
        );
    }

    public function test_version_with_a_future_publication_date_is_not_used_yet(): void
    {
        // K-15: yayın tarihi gelmemiş versiyon yeni kayıtlara uygulanmaz.
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator('Ahmet');
        $v1 = $machine->procedure->currentVersion();
        $v2 = $this->publishVersion($machine->procedure, [['steps' => 3]], publishedAt: now()->addDay());

        $before = $this->openCleaning($ahmet, $machine);
        $this->assertSame($v1->id, $before->procedure_version_id);

        $this->travel(1)->days();
        $after = $this->openCleaning($this->operator('Mehmet'), $machine);
        $this->assertSame($v2->id, $after->procedure_version_id);
    }

    public function test_minimum_duration_of_the_records_own_version_applies(): void
    {
        // R-11, K-15: minimum süre v1'de yok, v2'de 20 dk; v1 ile açılan kayıt gerekçesiz kapanır.
        $machine = $this->makeMachine([['steps' => 1, 'min_seconds' => 0]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->publishVersion($machine->procedure, [['steps' => 1, 'min_seconds' => 1200]]);

        $this->runStep($ahmet, $cleaning, 1, 60);

        $this->assertSame(CleaningStatus::Completed, $cleaning->fresh()->status);
        $phase = $this->phaseOf($cleaning, 1);
        $this->assertSame(PhaseStatus::Completed, $phase->status);
        $this->assertFalse($phase->below_minimum);
        $this->assertEquals(0, $this->lastEvent($cleaning, 'phase.completed')->payload['minimum_seconds']);
    }

    public function test_material_requirement_of_the_records_own_version_applies(): void
    {
        // K-13, K-15: v2 malzemeyi zorunlu yapar; v1 ile açılmış kayıt etkilenmez.
        $machine = $this->makeMachine([['steps' => 1]], materialRequired: false);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->publishVersion($machine->procedure, [['steps' => 1]], materialRequired: true);

        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
    }
}
