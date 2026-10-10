<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CancelReason;
use App\Enums\CleaningType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Kayıt numarası ve saha defteri referansı (K-17, R-17, R-18, R-19).
 */
class NumberingTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_record_number_describes_facility_line_machine_type_and_year(): void
    {
        $machine = $this->makeMachine(code: 'M03');

        $cleaning = $this->openCleaning($this->operator(), $machine, type: CleaningType::Planned);

        $this->assertSame('IST-H01M03-260001', $cleaning->fresh()->record_no);
    }

    public function test_unplanned_intervention_uses_m_and_shares_the_machine_sequence(): void
    {
        // K-17: planlı ve plansız kayıtlar aynı sırayı paylaşır.
        $machine = $this->makeMachine(code: 'M03');
        $ahmet = $this->operator();

        $planned = $this->openCleaning($ahmet, $machine, type: CleaningType::Planned);
        $unplanned = $this->openCleaning($ahmet, $machine, type: CleaningType::Unplanned);

        $this->assertSame('IST-H01M03-260001', $planned->fresh()->record_no);
        $this->assertSame('IST-H01M03-260002', $unplanned->fresh()->record_no);
    }

    public function test_record_sequence_is_kept_per_machine(): void
    {
        $m03 = $this->makeMachine(code: 'M03');
        $m04 = $this->makeMachine(code: 'M04');
        $ahmet = $this->operator();

        $this->openCleaning($ahmet, $m03);
        $this->openCleaning($ahmet, $m03);
        $first = $this->openCleaning($ahmet, $m04);

        $this->assertSame('IST-H01M04-260001', $first->fresh()->record_no);
    }

    public function test_record_sequence_restarts_every_year(): void
    {
        $machine = $this->makeMachine(code: 'M03');
        $ahmet = $this->operator();

        $this->at('10:00:00', '2026-12-31');
        $this->openCleaning($ahmet, $machine);
        $this->openCleaning($ahmet, $machine);
        $this->at('09:00:00', '2027-01-01');
        $newYear = $this->openCleaning($ahmet, $machine);

        $this->assertSame('IST-H01M03-270001', $newYear->fresh()->record_no);
    }

    public function test_planned_record_gets_its_field_reference_when_the_first_step_starts(): void
    {
        // K-17, R-18: açılışta yok; ilk adımda üretilir; sonraki adımlarda değişmez.
        $machine = $this->makeMachine([['steps' => 2]]);
        $ahmet = $this->operator();

        $cleaning = $this->openCleaning($ahmet, $machine, type: CleaningType::Planned);
        $this->assertNull($cleaning->fresh()->field_ref);

        $this->travel(5)->minutes();
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->assertSame('IST-SD-260001', $cleaning->fresh()->field_ref);
        $this->assertSame('IST-SD-260001', $this->lastEvent($cleaning, 'cleaning.started')->payload['field_ref']);

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2));
        $this->assertSame('IST-SD-260001', $cleaning->fresh()->field_ref);
    }

    public function test_unplanned_intervention_never_gets_a_field_reference(): void
    {
        // R-19, K-17, K-18
        $machine = $this->makeMachine([['steps' => 1]]);
        $ahmet = $this->operator();

        $cleaning = $this->openCleaning($ahmet, $machine, type: CleaningType::Unplanned);
        $this->assertNull($cleaning->fresh()->field_ref);

        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->assertNull($cleaning->fresh()->field_ref);
        $this->assertArrayHasKey('field_ref', $this->lastEvent($cleaning, 'cleaning.started')->payload);
        $this->assertNull($this->lastEvent($cleaning, 'cleaning.started')->payload['field_ref']);

        $this->travel(1)->minutes();
        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));
        $this->assertNull($cleaning->fresh()->field_ref);
    }

    public function test_unplanned_start_does_not_consume_a_field_reference(): void
    {
        $m03 = $this->makeMachine(code: 'M03');
        $m04 = $this->makeMachine(code: 'M04');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');

        $unplanned = $this->openCleaning($ahmet, $m03, type: CleaningType::Unplanned);
        $this->workflow()->startStep($ahmet, $this->stepOf($unplanned, 1));
        $planned = $this->openCleaning($mehmet, $m04, type: CleaningType::Planned);
        $this->workflow()->startStep($mehmet, $this->stepOf($planned, 1));

        $this->assertSame('IST-SD-260001', $planned->fresh()->field_ref);
    }

    public function test_records_that_never_start_leave_no_gap_in_the_field_book(): void
    {
        // K-17: iptal edilen ve süresi dolan kayıtlar saha defterinde boşluk bırakmaz.
        $machine = $this->makeMachine();
        $ahmet = $this->operator();

        $cancelled = $this->openCleaning($ahmet, $machine);
        $this->workflow()->cancel($ahmet, $cancelled->fresh(), CancelReason::InvalidRecord, 'Yanlış makine seçildi');
        $this->openCleaning($ahmet, $machine);
        $this->travel(31)->minutes();
        $this->assertSame(1, $this->workflow()->expireStale());

        $started = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($started, 1));

        $this->assertSame('IST-H01M03-260003', $started->fresh()->record_no);
        $this->assertSame('IST-SD-260001', $started->fresh()->field_ref);
    }

    public function test_field_reference_sequence_is_kept_per_facility_across_machines(): void
    {
        $m03 = $this->makeMachine(code: 'M03');
        $m04 = $this->makeMachine(code: 'M04');
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');

        $first = $this->openCleaning($ahmet, $m03);
        $second = $this->openCleaning($mehmet, $m04);
        $this->workflow()->startStep($mehmet, $this->stepOf($second, 1));
        $this->workflow()->startStep($ahmet, $this->stepOf($first, 1));

        $this->assertSame('IST-SD-260001', $second->fresh()->field_ref);
        $this->assertSame('IST-SD-260002', $first->fresh()->field_ref);
    }

    public function test_field_reference_year_is_the_year_of_the_first_step_start(): void
    {
        // K-17: numara açılışta (2026), saha referansı ilk adımda (2027) üretilir. Yıl yerel
        // takvime göredir: 20:50 / 21:05 UTC = 23:50 / 00:05 İstanbul.
        $machine = $this->makeMachine();
        $ahmet = $this->operator();

        $this->at('20:50:00', '2026-12-31');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->at('21:05:00', '2026-12-31');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $cleaning = $cleaning->fresh();
        $this->assertSame('IST-H01M03-260001', $cleaning->record_no);
        $this->assertSame('IST-SD-270001', $cleaning->field_ref);
    }
}
