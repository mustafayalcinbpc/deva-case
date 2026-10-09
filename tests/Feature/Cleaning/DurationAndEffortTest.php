<?php

namespace Tests\Feature\Cleaning;

use App\Enums\SliceEndReason;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Süre ve efor (R-22, R-26, K-03, K-04, K-10). Süre = dilim sürelerinin toplamı,
 * efor = Σ (dilim süresi × dilimdeki kişi sayısı).
 */
class DurationAndEffortTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('09:50:00');
    }

    public function test_duration_and_effort_are_kept_apart(): void
    {
        // R-26: adım 10:00–10:10, iki kişi → süre 10 dk (600 sn), efor 20 dk (1200 sn).
        $machine = $this->makeMachine([['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->at('10:00:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:10:00');

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));

        $cleaning = $cleaning->fresh();
        $this->assertSame(600, $cleaning->netSeconds());
        $this->assertSame(600, $cleaning->grossSeconds());
        $this->assertSame(1200, $cleaning->effortSeconds());

        $completed = $this->lastEvent($cleaning, 'cleaning.completed');
        $this->assertEquals(600, $completed->payload['net_seconds']);
        $this->assertEquals(600, $completed->payload['gross_seconds']);
        $this->assertEquals(1200, $completed->payload['effort_seconds']);
    }

    public function test_effort_is_computed_per_slice_when_workers_join_mid_step(): void
    {
        // K-04: 10:00–10:04 yalnız Ahmet, 10:04–10:10 Ahmet + Mehmet.
        // Süre 600 sn; efor 240×1 + 360×2 = 960 sn.
        $machine = $this->makeMachine([['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->at('10:00:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:04:00');

        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), $this->ids($ahmet, $mehmet));

        $slices = $this->stepOf($cleaning, 1)->slices()->get();
        $this->assertCount(2, $slices);
        $this->assertMoment('2026-10-09 10:04:00', $slices[0]->ended_at);
        $this->assertSame(SliceEndReason::WorkersChanged, $slices[0]->end_reason);
        $this->assertMoment('2026-10-09 10:04:00', $slices[1]->started_at, 'Yeni dilim aynı anda başlar.');
        $this->assertNull($slices[1]->ended_at);
        $this->assertSame([$ahmet->id], $this->sliceWorkerIds($slices[0]));
        $this->assertSame($this->sortedIds($ahmet, $mehmet), $this->sliceWorkerIds($slices[1]));

        $this->at('10:10:00');
        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));

        $cleaning = $cleaning->fresh();
        $this->assertSame(600, $cleaning->netSeconds());
        $this->assertSame(960, $cleaning->effortSeconds());
        $this->assertSame(SliceEndReason::Completed, $this->stepOf($cleaning, 1)->slices()->get()[1]->end_reason);
    }

    public function test_effort_drops_when_a_worker_leaves_mid_step(): void
    {
        // K-04: 10:00–10:06 Ahmet + Mehmet, 10:06–10:10 yalnız Mehmet (sahip çıkarıldı, K-10).
        // Süre 600 sn; efor 360×2 + 240×1 = 960 sn.
        $machine = $this->makeMachine([['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->at('10:00:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:06:00');
        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), [$mehmet->id]);
        $this->at('10:10:00');

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));

        $cleaning = $cleaning->fresh();
        $this->assertSame(600, $cleaning->netSeconds());
        $this->assertSame(960, $cleaning->effortSeconds());
        $this->assertSame([$mehmet->id], $this->sliceWorkerIds($this->stepOf($cleaning, 1)->slices()->get()[1]));
    }

    public function test_paused_time_counts_neither_as_duration_nor_as_effort(): void
    {
        // K-03: 10:00–10:05 çalışma, 10:05–10:45 duraklatıldı, 10:45–10:50 çalışma.
        $machine = $this->makeMachine([['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->at('10:00:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:05:00');
        $this->workflow()->pauseStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:45:00');
        $this->workflow()->resumeStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:50:00');

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));

        $cleaning = $cleaning->fresh();
        $this->assertSame(600, $cleaning->netSeconds());
        $this->assertSame(3000, $cleaning->grossSeconds());
        $this->assertSame(1200, $cleaning->effortSeconds());
        $this->assertEquals(600, $this->phaseOf($cleaning, 1)->measured_seconds);
    }

    public function test_owner_removed_from_a_step_does_not_count_in_its_effort(): void
    {
        // K-10: sahibi görevli listesinden çıkarılırsa o adımın eforuna sayılmaz.
        $machine = $this->makeMachine([['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), [$mehmet->id]);
        $this->at('10:00:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->at('10:10:00');

        $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1));

        $cleaning = $cleaning->fresh();
        $this->assertSame([$mehmet->id], $this->sliceWorkerIds($this->stepOf($cleaning, 1)->slices()->sole()));
        $this->assertSame(600, $cleaning->netSeconds());
        $this->assertSame(600, $cleaning->effortSeconds());
    }

    public function test_each_step_records_who_actually_worked_on_it(): void
    {
        // R-22: 1. adım yalnız Ahmet; 2. adım Ahmet + Mehmet; 3. adım yalnız Mehmet.
        // Her adım 5 dk → süre 900 sn; efor 300 + 600 + 300 = 1200 sn.
        $machine = $this->makeMachine([['steps' => 3]]);
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);
        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 1), [$ahmet->id]);
        $this->workflow()->setWorkers($ahmet, $this->stepOf($cleaning, 3), [$mehmet->id]);
        $this->at('10:00:00');

        $this->runStep($ahmet, $cleaning, 1, 300);
        $this->runStep($mehmet, $cleaning, 2, 300);
        $this->runStep($mehmet, $cleaning, 3, 300);

        $this->assertSame([$ahmet->id], $this->sliceWorkerIds($this->stepOf($cleaning, 1)->slices()->sole()));
        $this->assertSame($this->sortedIds($ahmet, $mehmet), $this->sliceWorkerIds($this->stepOf($cleaning, 2)->slices()->sole()));
        $this->assertSame([$mehmet->id], $this->sliceWorkerIds($this->stepOf($cleaning, 3)->slices()->sole()));
        $cleaning = $cleaning->fresh();
        $this->assertSame(900, $cleaning->netSeconds());
        $this->assertSame(1200, $cleaning->effortSeconds());
    }
}
