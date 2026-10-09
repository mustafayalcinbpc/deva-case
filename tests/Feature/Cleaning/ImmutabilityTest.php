<?php

namespace Tests\Feature\Cleaning;

use App\Models\Cleaning;
use App\Models\CleaningEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Değiştirilemezlik (R-15, R-46, R-47, R-48): sahiplik ve zaman damgaları Eloquent
 * üzerinden değiştirilemez; olay tablosu veritabanı seviyesinde yalnızca eklemeye açıktır.
 */
class ImmutabilityTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('09:00:00');
    }

    public function test_ownership_cannot_be_transferred(): void
    {
        // R-15, K-08: sorumluluk sonradan başka birinin üzerine geçirilemez.
        [$ahmet, $cleaning] = $this->startedCleaning();
        $mehmet = $this->operator('Mehmet');

        $this->assertThrows(fn () => $cleaning->fresh()->update(['owner_id' => $mehmet->id]), LogicException::class);

        $this->assertEquals($ahmet->id, $cleaning->fresh()->owner_id);
    }

    public function test_step_start_time_cannot_be_rewritten(): void
    {
        // R-47: bugün 10:05'te başlatılan adım yarın 09:30 olarak değiştirilemez.
        [, $cleaning] = $this->startedCleaning();
        $step = $this->stepOf($cleaning, 1);

        $this->assertThrows(function () use ($step) {
            $step->started_at = $step->started_at->addDay()->setTime(9, 30);
            $step->save();
        }, LogicException::class);

        $this->assertMoment('2026-10-09 10:05:00', $this->stepOf($cleaning, 1)->started_at);
    }

    public function test_work_slice_start_time_cannot_be_rewritten(): void
    {
        // R-47
        [, $cleaning] = $this->startedCleaning();
        $slice = $this->stepOf($cleaning, 1)->slices()->sole();

        $this->assertThrows(fn () => $slice->update(['started_at' => '2026-10-09 09:30:00']), LogicException::class);

        $this->assertMoment('2026-10-09 10:05:00', $this->stepOf($cleaning, 1)->slices()->sole()->started_at);
    }

    public function test_cleaning_start_time_cannot_be_rewritten(): void
    {
        // R-47
        [, $cleaning] = $this->startedCleaning();

        $this->assertThrows(fn () => $cleaning->fresh()->update(['started_at' => '2026-10-09 09:30:00']), LogicException::class);

        $this->assertMoment('2026-10-09 10:05:00', $cleaning->fresh()->started_at);
    }

    public function test_cleaning_records_cannot_be_deleted(): void
    {
        // R-48, R-13: geçmiş kayıtlar kaybolmaz.
        [, $cleaning] = $this->startedCleaning();

        $this->assertThrows(fn () => $cleaning->fresh()->delete(), LogicException::class);

        $this->assertNotNull(Cleaning::find($cleaning->id));
    }

    public function test_events_cannot_be_changed_or_deleted_through_eloquent(): void
    {
        [, $cleaning] = $this->startedCleaning();
        $event = $this->lastEvent($cleaning, 'step.started');

        $this->assertThrows(fn () => $event->update(['type' => 'step.forged']), LogicException::class);
        $this->assertThrows(fn () => $event->fresh()->delete(), LogicException::class);

        $this->assertSame('step.started', CleaningEvent::find($event->id)->type);
        $this->assertEventChainIntact($cleaning);
    }

    public function test_events_cannot_be_updated_at_database_level(): void
    {
        // R-46: Eloquent atlanıp doğrudan SQL ile güncelleme veritabanında reddedilir.
        [, $cleaning] = $this->startedCleaning();
        $types = $this->eventTypes($cleaning);

        $this->assertThrows(
            fn () => DB::table('cleaning_events')->where('cleaning_id', $cleaning->id)->update(['occurred_at' => '2026-10-09 09:30:00']),
            QueryException::class,
        );

        $this->assertSame($types, $this->eventTypes($cleaning));
        $this->assertMoment('2026-10-09 10:05:00', $this->lastEvent($cleaning, 'step.started')->occurred_at);
        $this->assertEventChainIntact($cleaning);
    }

    public function test_events_cannot_be_deleted_at_database_level(): void
    {
        // R-46, R-48
        [, $cleaning] = $this->startedCleaning();
        $types = $this->eventTypes($cleaning);

        $this->assertThrows(
            fn () => DB::table('cleaning_events')->where('cleaning_id', $cleaning->id)->delete(),
            QueryException::class,
        );

        $this->assertSame($types, $this->eventTypes($cleaning));
        $this->assertEventChainIntact($cleaning);
    }

    /**
     * Ahmet'in 10:00'da açtığı ve 10:05'te ilk adımını başlattığı temizlik.
     *
     * @return array{0: User, 1: Cleaning}
     */
    private function startedCleaning(): array
    {
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $this->at('10:00:00');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->at('10:05:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        return [$ahmet, $cleaning];
    }
}
