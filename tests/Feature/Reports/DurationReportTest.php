<?php

namespace Tests\Feature\Reports;

use App\Enums\CancelReason;
use App\Enums\CleaningType;
use App\Models\Cleaning;
use App\Models\Machine;
use App\Models\User;
use App\Services\Reports\DurationReport;
use App\Services\Reports\ReportFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\Feature\Reports\Concerns\BuildsReportScenarios;
use Tests\TestCase;

/**
 * Süre ve efor raporu (R-26, R-28, K-02, K-04): makine başına net / brüt süre, insan eforu ve
 * dilim sayısı; seçili makinede faz istatistikleri. Yalnızca tamamlanmış kayıtlar sayılır.
 */
class DurationReportTest extends TestCase
{
    use BuildsCleaningFixtures, BuildsReportScenarios, InteractsWithCleaningWorkflow, RefreshDatabase;

    private User $ahmet;

    private User $mehmet;

    protected function setUp(): void
    {
        parent::setUp();
        // Prosedür versiyonları bu andan önce yayımlanır; senaryolar daha sonraki zamanlarda geçer.
        $this->at('00:00:00', '2026-09-01');
        $this->ahmet = $this->operator('Ahmet');
        $this->mehmet = $this->operator('Mehmet');
    }

    public function test_machine_aggregates_net_gross_effort_and_slices_exactly(): void
    {
        $machine = $this->scenarioMachine();
        $this->cleaningA($machine);
        $this->cleaningB($machine);

        // Sayılmayanlar: devam eden ve iptal edilen kayıtlar (yalnızca tamamlanmış kayıtlar).
        $this->at('11:00:00');
        $running = $this->openCleaning($this->ahmet, $machine);
        $this->runStep($this->ahmet, $running, 1, 900);
        $this->at('12:00:00');
        $this->workflow()->cancel($this->manager(), $running, CancelReason::Other, 'Deneme');

        $this->at('13:00:00');
        $rows = $this->report()->machines($this->filters());

        $this->assertCount(1, $rows);
        $this->assertSame([
            'machine_id' => $machine->id,
            'facility_code' => 'IST',
            'facility_name' => 'İstanbul Tesisi',
            'line_code' => 'H01',
            'line_name' => 'Hat 1',
            'machine_code' => 'M03',
            'machine_name' => 'Makine M03',
            'machine_active' => true,
            'completed_count' => 2,
            // A: net 1500, brüt 3300, efor 3000, 4 dilim; B: net 1380, brüt 1560, efor 1380, 3 dilim.
            'avg_net_seconds' => 1440,
            'min_net_seconds' => 1380,
            'max_net_seconds' => 1500,
            'avg_gross_seconds' => 2430,
            'avg_effort_seconds' => 2190,
            'avg_slice_count' => 3.5,
        ], $rows->first());
    }

    public function test_aggregates_match_the_record_level_calculation(): void
    {
        $machine = $this->scenarioMachine();
        $a = $this->cleaningA($machine);
        $b = $this->cleaningB($machine);

        $this->assertSame([1500, 3300, 3000], [$a->netSeconds(), $a->grossSeconds(), $a->effortSeconds()]);
        $this->assertSame([1380, 1560, 1380], [$b->netSeconds(), $b->grossSeconds(), $b->effortSeconds()]);

        $cleanings = $this->report()->cleanings($this->filters(['machine_id' => $machine->id]), 25);

        $this->assertSame(
            [
                [$b->record_no, 1380, 1560, 1380, 3],
                [$a->record_no, 1500, 3300, 3000, 4],
            ],
            $cleanings->getCollection()->map(fn (Cleaning $cleaning) => [
                $cleaning->record_no,
                (int) $cleaning->net_seconds,
                (int) $cleaning->gross_seconds,
                (int) $cleaning->effort_seconds,
                (int) $cleaning->slice_count,
            ])->all(),
        );
    }

    public function test_phase_statistics_compare_measured_time_with_the_minimum(): void
    {
        $machine = $this->scenarioMachine();
        $this->cleaningA($machine);
        $this->cleaningB($machine);

        // C: 1. faz minimumun (10 dk) altında, 5 dk'da gerekçeyle kapandı.
        $this->at('14:00:00');
        $c = $this->openCleaning($this->ahmet, $machine);
        $this->runStep($this->ahmet, $c, 1, 300, 'Hat acil durduruldu');
        $this->completeRemainingSteps($this->ahmet, $c, 400);

        $phases = $this->report()->phases($this->filters(['machine_id' => $machine->id]));

        $this->assertSame([
            [
                'procedure_code' => 'PRC-M03', 'procedure_name' => 'M03 temizlik prosedürü', 'version' => 1,
                'sequence' => 1, 'name' => 'Faz 1', 'minimum_seconds' => 600, 'include_gaps' => false,
                // Ölçülen (net): A 600, B 720, C 300.
                'phase_count' => 3, 'avg_measured_seconds' => 540, 'min_measured_seconds' => 300,
                'max_measured_seconds' => 720, 'below_minimum_count' => 1,
                // Efor: A 600×2, B 720×1, C 300×1.
                'avg_net_seconds' => 540, 'avg_effort_seconds' => 740,
            ],
            [
                'procedure_code' => 'PRC-M03', 'procedure_name' => 'M03 temizlik prosedürü', 'version' => 1,
                'sequence' => 2, 'name' => 'Faz 2', 'minimum_seconds' => 300, 'include_gaps' => true,
                // Ölçülen (brüt): A 08:30–09:05 = 2100, B 10:15–10:26 = 660, C 800 (boşluksuz).
                'phase_count' => 3, 'avg_measured_seconds' => 1187, 'min_measured_seconds' => 660,
                'max_measured_seconds' => 2100, 'below_minimum_count' => 0,
                // Net: A 900, B 660, C 800. Efor: A 900×2, B 660, C 800.
                'avg_net_seconds' => 787, 'avg_effort_seconds' => 1087,
            ],
        ], $phases->all());
    }

    public function test_phase_rows_are_kept_apart_per_procedure_version(): void
    {
        // R-11: minimum süre yeni versiyonda değişir; iki versiyonun fazı aynı satırda toplanmaz.
        $machine = $this->makeMachine([['steps' => 1, 'min_seconds' => 60]]);
        $this->completedCleaning($this->ahmet, $machine, '2026-10-05 09:00:00', 120);
        $this->publishVersion($machine->procedure, [['steps' => 1, 'min_seconds' => 180]]);
        $this->completedCleaning($this->ahmet, $machine, '2026-10-06 09:00:00', 240);

        $this->at('12:00:00');
        $phases = $this->report()->phases($this->filters(['machine_id' => $machine->id]));

        $this->assertSame(
            [[1, 60, 120], [2, 180, 240]],
            $phases->map(fn (array $row) => [$row['version'], $row['minimum_seconds'], $row['avg_measured_seconds']])->all(),
        );
    }

    public function test_date_range_is_interpreted_in_istanbul_time(): void
    {
        $machine = $this->makeMachine([['steps' => 1]]);
        // 08.10 21:10 UTC = 09.10 00:10 İstanbul; 09.10 21:30 UTC = 10.10 00:30 İstanbul.
        $early = $this->completedCleaning($this->ahmet, $machine, '2026-10-08 21:10:00');
        $middle = $this->completedCleaning($this->ahmet, $machine, '2026-10-09 12:00:00');
        $late = $this->completedCleaning($this->ahmet, $machine, '2026-10-09 21:30:00');
        $this->at('22:00:00');

        $this->assertSame([$middle->id, $early->id], $this->recordIds(['from' => '2026-10-09', 'to' => '2026-10-09']));
        $this->assertSame([], $this->recordIds(['from' => '2026-10-08', 'to' => '2026-10-08']));
        $this->assertSame([$late->id], $this->recordIds(['from' => '2026-10-10', 'to' => '2026-10-10']));

        // Bitiş başlangıçtan önceyse ikisi yer değiştirir.
        $this->assertSame([$late->id, $middle->id, $early->id], $this->recordIds(['from' => '2026-10-10', 'to' => '2026-10-09']));
    }

    public function test_default_range_is_the_last_30_days_in_istanbul_time(): void
    {
        $machine = $this->makeMachine([['steps' => 1]]);
        // Bugün 09.10 (İstanbul); aralık 10.09 00:00 İstanbul = 09.09 21:00 UTC'de başlar.
        $this->completedCleaning($this->ahmet, $machine, '2026-09-09 20:40:00');
        $first = $this->completedCleaning($this->ahmet, $machine, '2026-09-09 21:01:00');
        $last = $this->completedCleaning($this->ahmet, $machine, '2026-10-09 11:00:00');
        $this->at('12:00:00');

        $filters = ReportFilters::fromArray([]);

        $this->assertSame('2026-09-10', $filters->from->format('Y-m-d'));
        $this->assertSame('2026-10-09', $filters->to->format('Y-m-d'));
        $this->assertSame([$last->id, $first->id], $this->recordIds([]));

        $this->actingAs($this->manager())->get(route('reports.durations'))
            ->assertOk()
            ->assertSee('value="2026-09-10"', false)
            ->assertSee('value="2026-10-09"', false);
    }

    public function test_type_machine_and_location_filters(): void
    {
        $m03 = $this->makeMachine([['steps' => 1]], code: 'M03');
        $m04 = $this->makeMachine([['steps' => 1]], code: 'M04');
        $ank = $this->makeMachineAt('ANK', 'H02', 'M01');

        $planned = $this->completedCleaning($this->ahmet, $m03, '2026-10-09 08:00:00', 600);
        $unplanned = $this->completedCleaning($this->ahmet, $m03, '2026-10-09 09:00:00', 300, CleaningType::Unplanned);
        $other = $this->completedCleaning($this->ahmet, $m04, '2026-10-09 10:00:00', 900);
        $ankara = $this->completedCleaning($this->mehmet, $ank, '2026-10-09 11:00:00', 1200);
        $this->at('12:00:00');

        // Sıra: tesis, hat, makine kodu (ANK, IST).
        $this->assertSame([$ank->id => 1200, $m03->id => 450, $m04->id => 900], $this->averageNetByMachine([]));
        $this->assertSame([$ank->id => 1200, $m03->id => 600, $m04->id => 900], $this->averageNetByMachine(['type' => 'planned']));
        $this->assertSame([$m03->id => 300], $this->averageNetByMachine(['type' => 'unplanned']));
        $this->assertSame([$m04->id => 900], $this->averageNetByMachine(['machine_id' => $m04->id]));
        $this->assertSame([$ank->id => 1200], $this->averageNetByMachine(['facility_id' => $ank->line->facility_id]));
        $this->assertSame([$m03->id => 450, $m04->id => 900], $this->averageNetByMachine(['line_id' => $m03->line_id]));

        $this->assertSame([$unplanned->id, $planned->id], $this->recordIds(['machine_id' => $m03->id]));
        $this->assertSame([$ankara->id, $other->id, $planned->id], $this->recordIds(['type' => 'planned']));

        // Geçersiz değerler yok sayılır.
        $this->assertCount(3, $this->report()->machines($this->filters(['type' => 'x', 'machine_id' => 'abc', 'from' => '2026-13-45'])));
    }

    public function test_aggregation_runs_in_a_fixed_number_of_queries(): void
    {
        $machine = $this->makeMachine([['steps' => 2]]);

        foreach (range(1, 4) as $day) {
            $this->completedCleaning($this->ahmet, $machine, "2026-10-0{$day} 09:00:00");
        }

        $this->at('12:00:00');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $this->report()->machines($this->filters());
        $this->report()->phases($this->filters(['machine_id' => $machine->id]));
        DB::disableQueryLog();

        $this->assertSame(4, $rows->first()['completed_count']);
        $this->assertCount(2, DB::getQueryLog(), 'Makine ve faz özeti birer SQL sorgusuyla hesaplanır.');
    }

    public function test_page_shows_machine_summary_phases_and_records_with_audit_links(): void
    {
        $machine = $this->scenarioMachine();
        $a = $this->cleaningA($machine);
        $this->cleaningB($machine);
        $this->at('13:00:00');
        $manager = $this->manager();

        $overview = $this->actingAs($manager)->get(route('reports.durations'))->assertOk();
        $row = $this->one($overview, "#duration-machines tr[data-machine-id=\"{$machine->id}\"]");

        $this->assertSame(
            'M03 — Makine M03 IST / H01 2 24 dk 00 sn 23 dk 00 sn 25 dk 00 sn 40 dk 30 sn 36 dk 30 sn 3,5 Fazlar ve kayıtlar',
            $this->text($row),
        );
        $this->assertNull($this->page($overview)->querySelector('#duration-phases'));

        $detail = $this->actingAs($manager)->get(route('reports.durations', ['machine_id' => $machine->id]))->assertOk();

        $this->assertSame(
            [
                'PRC-M03 v1 1. Faz 1 Net 10 dk 00 sn 11 dk 00 sn 10 dk 00 sn 12 dk 00 sn 11 dk 00 sn 16 dk 00 sn 0 / 2',
                'PRC-M03 v1 2. Faz 2 Brüt 5 dk 00 sn 23 dk 00 sn 11 dk 00 sn 35 dk 00 sn 13 dk 00 sn 20 dk 30 sn 0 / 2',
            ],
            $this->texts($detail, '#duration-phases tbody tr'),
        );
        $this->assertCount(2, $this->texts($detail, '#duration-cleanings tbody tr'));
        $this->one($detail, '#duration-cleanings a[href="'.route('reports.audit', $a).'"]');
        $this->one($detail, '#duration-cleanings a[href="'.route('cleanings.show', $a).'"]');
    }

    // -------------------------------------------------------------------------------------------

    /**
     * 2 faz: 1. faz tek adım, minimum 10 dk, net; 2. faz iki adım, minimum 5 dk, boşluklar dahil (brüt).
     */
    private function scenarioMachine(): Machine
    {
        return $this->makeMachine([
            ['steps' => 1, 'min_seconds' => 600],
            ['steps' => 2, 'min_seconds' => 300, 'include_gaps' => true],
        ]);
    }

    /**
     * Ahmet + Mehmet (2 kişi): 1. adım 08:10–08:20; 2. adım 08:30–08:35, duraklatıldı, 08:45–08:50;
     * 3. adım 09:00–09:05. Net 1500, brüt 08:10–09:05 = 3300, efor 3000, 4 dilim.
     */
    private function cleaningA(Machine $machine): Cleaning
    {
        $this->at('08:00:00');
        $cleaning = $this->openCleaning($this->ahmet, $machine, [$this->mehmet]);

        $this->at('08:10:00');
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 1));
        $this->at('08:20:00');
        $this->workflow()->completeStep($this->ahmet, $this->stepOf($cleaning, 1));

        $this->at('08:30:00');
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 2));
        $this->at('08:35:00');
        $this->workflow()->pauseStep($this->ahmet, $this->stepOf($cleaning, 2));
        $this->at('08:45:00');
        $this->workflow()->resumeStep($this->ahmet, $this->stepOf($cleaning, 2));
        $this->at('08:50:00');
        $this->workflow()->completeStep($this->ahmet, $this->stepOf($cleaning, 2));

        $this->at('09:00:00');
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 3));
        $this->at('09:05:00');
        $this->workflow()->completeStep($this->ahmet, $this->stepOf($cleaning, 3));

        return $cleaning->refresh();
    }

    /**
     * Yalnız Ahmet: 1. adım 10:00–10:12; 2. adım 10:15–10:20; 3. adım 10:20–10:26.
     * Net 1380, brüt 10:00–10:26 = 1560, efor 1380, 3 dilim.
     */
    private function cleaningB(Machine $machine): Cleaning
    {
        $this->at('09:55:00');
        $cleaning = $this->openCleaning($this->ahmet, $machine);

        $this->at('10:00:00');
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:12:00');
        $this->workflow()->completeStep($this->ahmet, $this->stepOf($cleaning, 1));

        $this->at('10:15:00');
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 2));
        $this->at('10:20:00');
        $this->workflow()->completeStep($this->ahmet, $this->stepOf($cleaning, 2));
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 3));
        $this->at('10:26:00');
        $this->workflow()->completeStep($this->ahmet, $this->stepOf($cleaning, 3));

        return $cleaning->refresh();
    }

    private function report(): DurationReport
    {
        return app(DurationReport::class);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function filters(array $input = []): ReportFilters
    {
        return ReportFilters::fromArray($input);
    }

    /**
     * Filtreye uyan tamamlanmış kayıtlar, en son tamamlanan önce.
     *
     * @param  array<string, mixed>  $input
     * @return list<int>
     */
    private function recordIds(array $input): array
    {
        return $this->report()->cleanings($this->filters($input), 100)
            ->getCollection()
            ->map(fn (Cleaning $cleaning) => $cleaning->id)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<int, int>
     */
    private function averageNetByMachine(array $input): array
    {
        return $this->report()->machines($this->filters($input))
            ->mapWithKeys(fn (array $row) => [$row['machine_id'] => $row['avg_net_seconds']])
            ->all();
    }
}
