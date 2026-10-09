<?php

namespace Tests\Feature\Reports;

use App\Enums\CleaningType;
use App\Models\CleaningPhase;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkSlice;
use App\Services\Reports\DeviationReport;
use App\Services\Reports\ReportFilters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\Feature\Reports\Concerns\BuildsReportScenarios;
use Tests\TestCase;

/**
 * Sapmalar: minimum süresinin altında kapanan fazlar (K-01) ve eşiği aşan çalışma dilimleri
 * (K-03, cleaning.slice_anomaly_hours).
 */
class DeviationReportTest extends TestCase
{
    use BuildsCleaningFixtures, BuildsReportScenarios, InteractsWithCleaningWorkflow, RefreshDatabase;

    private User $ahmet;

    private User $mehmet;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('00:00:00', '2026-10-01');
        config(['cleaning.slice_anomaly_hours' => 4]);
        $this->ahmet = $this->operator('Ahmet');
        $this->mehmet = $this->operator('Mehmet');
    }

    public function test_phases_closed_below_minimum_are_listed_with_reason_and_closer(): void
    {
        $machine = $this->makeMachine([['steps' => 1, 'min_seconds' => 600], ['steps' => 1, 'min_seconds' => 600]]);

        // 1. faz: Mehmet (yardımcı) 08:00–08:05'te 5 dk'da kapattı; 2. faz 10 dk, sapma yok.
        $this->at('07:55:00');
        $cleaning = $this->openCleaning($this->ahmet, $machine, [$this->mehmet]);
        $this->at('08:00:00');
        $this->workflow()->startStep($this->mehmet, $this->stepOf($cleaning, 1));
        $this->at('08:05:00');
        $this->workflow()->completeStep($this->mehmet, $this->stepOf($cleaning, 1), 'Makine erken durdu');
        $this->runStep($this->ahmet, $cleaning, 2, 600);
        $this->at('12:00:00');

        $phases = app(DeviationReport::class)->belowMinimum(ReportFilters::fromArray([]), 25, 'phases_page');

        $this->assertSame(1, $phases->total());
        $phase = $phases->first();
        $this->assertSame([1, 300, 'Makine erken durdu', 'Mehmet'], [(int) $phase->sequence, (int) $phase->measured_seconds, $phase->deviation_reason, $phase->closedBy->name]);

        $response = $this->actingAs($this->manager())->get(route('reports.deviations'))->assertOk();

        $this->assertSame(
            [$cleaning->record_no.' IST / H01 / M03 1. Faz 1 (net) 5 dk 00 sn 10 dk 00 sn 5 dk 00 sn Makine erken durdu Mehmet 09.10.2026 11:05:00 Denetim raporu'],
            $this->texts($response, '#below-minimum tbody tr'),
        );
        $this->one($response, '#below-minimum a[href="'.route('cleanings.show', $cleaning).'"]');
        $this->one($response, '#below-minimum a[href="'.route('reports.audit', $cleaning).'"]');
    }

    public function test_below_minimum_list_follows_the_filters(): void
    {
        $m03 = $this->makeMachine([['steps' => 1, 'min_seconds' => 600]], code: 'M03');
        $m04 = $this->makeMachine([['steps' => 1, 'min_seconds' => 600]], code: 'M04');

        // 09.10 21:30 UTC = 10.10 00:30 İstanbul.
        $planned = $this->belowMinimum($m03, '2026-10-09 08:00:00');
        $unplanned = $this->belowMinimum($m03, '2026-10-09 21:30:00', CleaningType::Unplanned);
        $other = $this->belowMinimum($m04, '2026-10-08 12:00:00');
        $this->at('23:00:00');

        $ids = fn (array $input) => app(DeviationReport::class)
            ->belowMinimum(ReportFilters::fromArray($input), 25, 'phases_page')
            ->getCollection()
            ->map(fn (CleaningPhase $phase) => $phase->cleaning_id)
            ->all();

        $this->assertSame([$unplanned, $planned, $other], $ids([]));
        $this->assertSame([$planned, $other], $ids(['to' => '2026-10-09']));
        $this->assertSame([$unplanned], $ids(['from' => '2026-10-10', 'to' => '2026-10-10']));
        $this->assertSame([$unplanned], $ids(['type' => 'unplanned']));
        $this->assertSame([$other], $ids(['machine_id' => $m04->id]));
    }

    public function test_slices_longer_than_the_threshold_are_flagged_including_open_ones(): void
    {
        $machine = $this->makeMachine([['steps' => 3]]);
        $cleaning = $this->openCleaning($this->ahmet, $machine, [$this->mehmet]);

        // 1. adım tam 4 saat (eşikten uzun değil), 2. adım 4 sa 30 dk, 3. adım açık ve 5 saattir sürüyor.
        $this->at('06:00:00', '2026-10-09');
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:00:00', '2026-10-09');
        $this->workflow()->completeStep($this->ahmet, $this->stepOf($cleaning, 1));
        $this->workflow()->setWorkers($this->ahmet, $this->stepOf($cleaning, 2), [$this->mehmet->id]);
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 2));
        $this->at('14:30:00', '2026-10-09');
        $this->workflow()->completeStep($this->ahmet, $this->stepOf($cleaning, 2));
        $this->at('15:00:00', '2026-10-09');
        $this->workflow()->startStep($this->ahmet, $this->stepOf($cleaning, 3));
        $this->at('20:00:00', '2026-10-09');

        $slices = app(DeviationReport::class)->anomalousSlices(ReportFilters::fromArray([]), 25, 'slices_page');

        $this->assertSame(
            [[3, 18000, null], [2, 16200, '2026-10-09 14:30:00']],
            $slices->getCollection()->map(fn (WorkSlice $slice) => [
                $slice->step->sequence,
                (int) $slice->duration_seconds,
                $slice->ended_at?->format('Y-m-d H:i:s'),
            ])->all(),
        );

        $response = $this->actingAs($this->manager())->get(route('reports.deviations'))->assertOk();

        $this->assertSame(
            [
                $cleaning->record_no.' IST / H01 / M03 3. Faz 1 adım 3 09.10.2026 18:00:00 Devam ediyor 5 sa 00 dk Ahmet, Mehmet Denetim raporu',
                $cleaning->record_no.' IST / H01 / M03 2. Faz 1 adım 2 09.10.2026 13:00:00 09.10.2026 17:30:00 4 sa 30 dk Mehmet Denetim raporu',
            ],
            $this->texts($response, '#anomalous-slices tbody tr'),
        );
        $this->assertStringContainsString('4 saatten uzun çalışma dilimleri', $this->text($this->one($response, '#anomalous-slices-title')));
    }

    public function test_threshold_comes_from_configuration(): void
    {
        $machine = $this->makeMachine([['steps' => 1]]);
        $this->completedCleaning($this->ahmet, $machine, '2026-10-09 10:00:00', 3 * 3600);
        $this->at('12:00:00');
        $report = app(DeviationReport::class);

        $this->assertSame(0, $report->anomalousSlices(ReportFilters::fromArray([]), 25, 'slices_page')->total());

        config(['cleaning.slice_anomaly_hours' => 2]);

        $this->assertSame(1, $report->anomalousSlices(ReportFilters::fromArray([]), 25, 'slices_page')->total());
    }

    public function test_empty_report_explains_itself(): void
    {
        $this->actingAs($this->manager())->get(route('reports.deviations'))
            ->assertOk()
            ->assertSee('Seçilen aralıkta minimum süresinin altında kapanan faz yok.')
            ->assertSee('Seçilen aralıkta eşiği aşan çalışma dilimi yok.');
    }

    /**
     * Tek adımlı fazı 5 dakikada, gerekçeyle kapatır; kayıt $closeAt (UTC) anında tamamlanır.
     */
    private function belowMinimum(Machine $machine, string $closeAt, CleaningType $type = CleaningType::Planned): int
    {
        [$date, $time] = explode(' ', $closeAt);
        $this->travelTo($this->at($time, $date)->subMinutes(6));
        $cleaning = $this->openCleaning($this->ahmet, $machine, type: $type);
        $this->travel(60)->seconds();
        $this->runStep($this->ahmet, $cleaning, 1, 300, 'Kısa sürdü');

        return $cleaning->id;
    }
}
