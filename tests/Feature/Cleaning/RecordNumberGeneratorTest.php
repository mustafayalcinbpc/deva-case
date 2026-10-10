<?php

namespace Tests\Feature\Cleaning;

use App\Models\Facility;
use App\Services\Cleaning\RecordNumberGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\TestCase;

class RecordNumberGeneratorTest extends TestCase
{
    use BuildsCleaningFixtures, RefreshDatabase;

    private RecordNumberGenerator $numbers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->numbers = app(RecordNumberGenerator::class);
    }

    public function test_record_number_describes_where_the_cleaning_is_done(): void
    {
        $machine = $this->makeMachine();

        $this->assertSame('IST-H01M03-260001', $this->numbers->recordNo($machine, $this->at('2026-10-09')));
    }

    public function test_record_number_sequence_increments_and_is_stored_in_the_counter(): void
    {
        $machine = $this->makeMachine();
        $at = $this->at('2026-10-09');

        $this->assertSame('IST-H01M03-260001', $this->numbers->recordNo($machine, $at));
        $this->assertSame('IST-H01M03-260002', $this->numbers->recordNo($machine, $at));
        $this->assertSame('IST-H01M03-260003', $this->numbers->recordNo($machine, $at));

        $this->assertDatabaseHas('sequence_counters', ['key' => "cleaning:{$machine->id}:2026", 'value' => 3]);
    }

    public function test_sequence_beyond_four_digits_keeps_the_two_digit_year(): void
    {
        $machine = $this->makeMachine();
        DB::table('sequence_counters')->insert(['key' => "cleaning:{$machine->id}:2026", 'value' => 9998]);
        $at = $this->at('2026-10-09');

        $this->assertSame('IST-H01M03-269999', $this->numbers->recordNo($machine, $at));
        $this->assertSame('IST-H01M03-2610000', $this->numbers->recordNo($machine, $at));
    }

    public function test_each_machine_has_its_own_sequence(): void
    {
        $m03 = $this->makeMachine(code: 'M03');
        $m04 = $this->makeMachine(code: 'M04');
        $at = $this->at('2026-10-09');

        $this->assertSame('IST-H01M03-260001', $this->numbers->recordNo($m03, $at));
        $this->assertSame('IST-H01M03-260002', $this->numbers->recordNo($m03, $at));
        $this->assertSame('IST-H01M04-260001', $this->numbers->recordNo($m04, $at));
    }

    public function test_record_number_sequence_restarts_every_year(): void
    {
        $machine = $this->makeMachine();

        // Yıl tesisin yerel takvimine göredir (Europe/Istanbul, UTC+3).
        $this->assertSame('IST-H01M03-260001', $this->numbers->recordNo($machine, $this->local('2026-12-31 23:59:59')));
        $this->assertSame('IST-H01M03-260002', $this->numbers->recordNo($machine, $this->local('2026-12-31 23:59:59')));
        $this->assertSame('IST-H01M03-270001', $this->numbers->recordNo($machine, $this->local('2027-01-01 00:00:00')));
        $this->assertSame('IST-H01M03-270002', $this->numbers->recordNo($machine, $this->local('2027-03-15')));
    }

    public function test_year_follows_local_calendar_when_utc_is_still_last_year(): void
    {
        // 31.12.2026 22:30 UTC = 1 Ocak 2027 01:30 İstanbul: kayıt yeni yılın numarasını alır.
        $machine = $this->makeMachine();
        $facility = $machine->line->facility;
        $at = $this->at('2026-12-31 22:30:00');

        $this->assertSame('IST-H01M03-270001', $this->numbers->recordNo($machine, $at));
        $this->assertSame('IST-SD-270001', $this->numbers->fieldRef($facility, $at));
    }

    public function test_field_ref_format_and_sequence(): void
    {
        $facility = $this->makeMachine()->line->facility;
        $at = $this->at('2026-10-09');

        $this->assertSame('IST-SD-260001', $this->numbers->fieldRef($facility, $at));
        $this->assertSame('IST-SD-260002', $this->numbers->fieldRef($facility, $at));

        $this->assertDatabaseHas('sequence_counters', ['key' => "field-ref:{$facility->id}:2026", 'value' => 2]);
    }

    public function test_field_ref_sequence_is_per_facility_and_shared_by_its_machines(): void
    {
        $m03 = $this->makeMachine(code: 'M03');
        $m04 = $this->makeMachine(code: 'M04');
        $ist = $m03->line->facility;
        $ank = Facility::create(['code' => 'ANK', 'name' => 'Ankara Tesisi']);
        $at = $this->at('2026-10-09');

        // Kayıt numarası sayaçları saha defteri sırasını etkilemez.
        $this->numbers->recordNo($m03, $at);
        $this->numbers->recordNo($m04, $at);

        $this->assertSame('IST-SD-260001', $this->numbers->fieldRef($m03->line->facility, $at));
        $this->assertSame('IST-SD-260002', $this->numbers->fieldRef($m04->line->facility, $at));
        $this->assertSame('ANK-SD-260001', $this->numbers->fieldRef($ank, $at));
        $this->assertSame('IST-SD-260003', $this->numbers->fieldRef($ist, $at));
    }

    public function test_field_ref_sequence_restarts_every_year(): void
    {
        $facility = $this->makeMachine()->line->facility;

        $this->assertSame('IST-SD-260001', $this->numbers->fieldRef($facility, $this->local('2026-12-31 23:59:59')));
        $this->assertSame('IST-SD-270001', $this->numbers->fieldRef($facility, $this->local('2027-01-01 00:00:00')));
        $this->assertSame('IST-SD-260002', $this->numbers->fieldRef($facility, $this->local('2026-06-01')));
    }

    private function at(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time);
    }

    /**
     * Gösterim saat dilimindeki yerel zaman, UTC'ye çevrilmiş olarak (iş akışı zamanı UTC tutar).
     */
    private function local(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse($time, config('app.display_timezone'))->utc();
    }
}
