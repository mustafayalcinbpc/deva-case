<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CleaningStatus;
use App\Enums\PhaseStatus;
use App\Enums\StepStatus;
use App\Models\Cleaning;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Faz minimum süresi (R-27, K-01) ve adımlar arası boşlukların ölçüme dahil
 * edilmesi (R-28, K-02).
 */
class MinimumDurationTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:30:00');
    }

    public static function missingReasons(): iterable
    {
        yield 'gerekçe verilmemiş' => [null];
        yield 'boş gerekçe' => [''];
    }

    #[DataProvider('missingReasons')]
    public function test_phase_below_minimum_without_reason_is_rejected_and_the_step_keeps_running(?string $reason): void
    {
        // K-01: 15 dk minimum, 10 dk çalışıldı, gerekçe yok → hiçbir değişiklik kalmaz.
        [$ahmet, $cleaning] = $this->singleStepRunFor(600, minimumSeconds: 900);
        $events = $this->eventTypes($cleaning);

        $e = $this->assertRuleViolation('below_minimum_duration', fn () => $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1), $reason));

        $this->assertSame(600, $e->context['measured_seconds']);
        $this->assertSame(900, $e->context['minimum_seconds']);
        $step = $this->stepOf($cleaning, 1);
        $this->assertSame(StepStatus::Running, $step->status);
        $this->assertNull($step->completed_at);
        $this->assertNull($step->slices()->sole()->ended_at, 'Dilim açık kalmalı.');
        $phase = $this->phaseOf($cleaning, 1);
        $this->assertSame(PhaseStatus::InProgress, $phase->status);
        $this->assertNull($phase->measured_seconds);
        $this->assertFalse($phase->below_minimum);
        $this->assertSame(CleaningStatus::InProgress, $cleaning->fresh()->status);
        $this->assertSame($events, $this->eventTypes($cleaning));
    }

    public function test_whitespace_only_reason_counts_as_missing(): void
    {
        // K-01: "gerekçe yazmak zorunlu" — yalnızca boşluk gerekçe değildir (yorum).
        [$ahmet, $cleaning] = $this->singleStepRunFor(600, minimumSeconds: 900);

        $this->assertRuleViolation('below_minimum_duration', fn () => $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1), '   '));

        $this->assertSame(StepStatus::Running, $this->stepOf($cleaning, 1)->status);
    }

    public function test_phase_below_minimum_with_reason_closes_as_a_deviation(): void
    {
        // K-01: faz yine kapanır ama "minimum süre altında" olarak işaretlenir.
        [$ahmet, $cleaning] = $this->singleStepRunFor(600, minimumSeconds: 900);

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1), 'Hat acil üretime alındı');

        $phase = $this->phaseOf($cleaning, 1);
        $this->assertSame(PhaseStatus::Completed, $phase->status);
        $this->assertTrue($phase->below_minimum);
        $this->assertSame('Hat acil üretime alındı', $phase->deviation_reason);
        $this->assertEquals(600, $phase->measured_seconds);
        $this->assertMoment('2026-10-09 10:10:00', $phase->completed_at);
        $this->assertSame(StepStatus::Completed, $this->stepOf($cleaning, 1)->status);
        $this->assertSame(CleaningStatus::Completed, $cleaning->fresh()->status);

        $event = $this->lastEvent($cleaning, 'phase.completed');
        $this->assertEquals($phase->id, $event->payload['phase_id']);
        $this->assertEquals(1, $event->payload['sequence']);
        $this->assertEquals(600, $event->payload['measured_seconds']);
        $this->assertEquals(900, $event->payload['minimum_seconds']);
        $this->assertTrue($event->payload['below_minimum']);
        $this->assertSame('Hat acil üretime alındı', $event->payload['deviation_reason']);
    }

    public function test_reason_is_ignored_when_the_minimum_is_met(): void
    {
        // Sözleşme 1B: süre yeterliyse gerekçe yok sayılır.
        [$ahmet, $cleaning] = $this->singleStepRunFor(1200, minimumSeconds: 900);

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1), 'Gereksiz gerekçe');

        $phase = $this->phaseOf($cleaning, 1);
        $this->assertSame(PhaseStatus::Completed, $phase->status);
        $this->assertFalse($phase->below_minimum);
        $this->assertNull($phase->deviation_reason);
        $this->assertEquals(1200, $phase->measured_seconds);
        $event = $this->lastEvent($cleaning, 'phase.completed');
        $this->assertFalse($event->payload['below_minimum']);
        $this->assertNull($event->payload['deviation_reason']);
    }

    public function test_exactly_the_minimum_is_not_a_deviation(): void
    {
        // "measured < minimum" kuralı: eşitlik yeterlidir.
        [$ahmet, $cleaning] = $this->singleStepRunFor(900, minimumSeconds: 900);

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));

        $phase = $this->phaseOf($cleaning, 1);
        $this->assertSame(PhaseStatus::Completed, $phase->status);
        $this->assertFalse($phase->below_minimum);
    }

    public function test_minimum_is_only_checked_when_the_phase_closes(): void
    {
        // Fazın son adımı değilse kısa adım gerekçe istemez.
        $machine = $this->makeMachine([['steps' => 2, 'min_seconds' => 900]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->at('10:00:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(12)->seconds();

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertSame(StepStatus::Completed, $this->stepOf($cleaning, 1)->status);
        $this->assertSame(PhaseStatus::InProgress, $this->phaseOf($cleaning, 1)->status);
    }

    public function test_gap_between_steps_is_excluded_when_the_phase_does_not_include_gaps(): void
    {
        // R-28, K-02: 1. adım 12 sn, 40 dk boşluk, 2. adım 60 sn. Boşluk dahil değil →
        // ölçülen süre net 72 sn < 900 sn minimum.
        [$ahmet, $cleaning] = $this->twoStepsWithFortyMinuteGap(includeGaps: false);

        $e = $this->assertRuleViolation('below_minimum_duration', fn () => $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 2)));

        $this->assertSame(72, $e->context['measured_seconds']);
        $this->assertSame(900, $e->context['minimum_seconds']);
        $this->assertSame(StepStatus::Running, $this->stepOf($cleaning, 2)->status);

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 2), 'Operatör yemeğe çıktı');

        $phase = $this->phaseOf($cleaning, 1);
        $this->assertEquals(72, $phase->measured_seconds);
        $this->assertTrue($phase->below_minimum);
    }

    public function test_gap_between_steps_is_included_when_the_phase_includes_gaps(): void
    {
        // R-28, K-02: aynı senaryo, boşluk dahil → brüt 09:00:00–09:41:12 = 2472 sn ≥ 900 sn.
        [$ahmet, $cleaning] = $this->twoStepsWithFortyMinuteGap(includeGaps: true);

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 2));

        $phase = $this->phaseOf($cleaning, 1);
        $this->assertSame(PhaseStatus::Completed, $phase->status);
        $this->assertEquals(2472, $phase->measured_seconds);
        $this->assertFalse($phase->below_minimum);
        $this->assertEquals(2472, $this->lastEvent($cleaning, 'phase.completed')->payload['measured_seconds']);
    }

    public function test_cleaning_reports_both_net_and_gross_duration(): void
    {
        // K-02: raporda temizliğin toplam net ve brüt süresi birlikte gösterilir.
        [$ahmet, $cleaning] = $this->twoStepsWithFortyMinuteGap(includeGaps: true);

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 2));

        $cleaning = $cleaning->fresh();
        $this->assertSame(CleaningStatus::Completed, $cleaning->status);
        $this->assertSame(72, $cleaning->netSeconds());
        $this->assertSame(2472, $cleaning->grossSeconds());
        $this->assertSame(72, $cleaning->effortSeconds());
        $event = $this->lastEvent($cleaning, 'cleaning.completed');
        $this->assertEquals(72, $event->payload['net_seconds']);
        $this->assertEquals(2472, $event->payload['gross_seconds']);
        $this->assertEquals(72, $event->payload['effort_seconds']);
    }

    public function test_each_phase_is_checked_against_its_own_minimum(): void
    {
        // R-06: 1. faz minimumsuz, 2. faz 5 dk; 2. fazın 2 dk'lık adımı gerekçe ister.
        $machine = $this->makeMachine([['steps' => 1], ['steps' => 1, 'min_seconds' => 300]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->at('10:00:00');
        $this->runStep($ahmet, $cleaning, 1, 10);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2));
        $this->travel(120)->seconds();

        $e = $this->assertRuleViolation('below_minimum_duration', fn () => $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 2)));

        $this->assertSame(120, $e->context['measured_seconds']);
        $this->assertSame(300, $e->context['minimum_seconds']);
        $this->assertSame(PhaseStatus::Completed, $this->phaseOf($cleaning, 1)->status);
        $this->assertFalse($this->phaseOf($cleaning, 1)->below_minimum);
    }

    /**
     * Tek adımlı, tek fazlı temizlik; adım 10:00'da başlar ve $seconds kadar çalışır.
     *
     * @return array{0: User, 1: Cleaning}
     */
    private function singleStepRunFor(int $seconds, int $minimumSeconds): array
    {
        $machine = $this->makeMachine([['steps' => 1, 'min_seconds' => $minimumSeconds]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->at('10:00:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel($seconds)->seconds();

        return [$ahmet, $cleaning];
    }

    /**
     * R-28 örneği: 09:00:00 1. adım başlar, 12 sn sonra biter; 40 dk boşluk; 09:40:12'de
     * 2. adım başlar ve 60 sn çalışır (tamamlanmadan bırakılır). Faz minimumu 15 dk.
     *
     * @return array{0: User, 1: Cleaning}
     */
    private function twoStepsWithFortyMinuteGap(bool $includeGaps): array
    {
        $machine = $this->makeMachine([['steps' => 2, 'min_seconds' => 900, 'include_gaps' => $includeGaps]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->at('09:00:00');
        $this->runStep($ahmet, $cleaning, 1, 12);
        $this->travel(40)->minutes();
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 2));
        $this->travel(60)->seconds();

        return [$ahmet, $cleaning];
    }
}
