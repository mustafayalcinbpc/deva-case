<?php

namespace Tests\Feature\Cleaning;

use App\Enums\CancelReason;
use App\Enums\CleaningStatus;
use App\Enums\StepStatus;
use App\Models\CleaningEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Başlamamış kayıtların süresinin dolması (K-06; R-31, R-32, R-33, R-37).
 * Ölçüt: status = created ve created_at <= şimdi − stale_after_minutes (varsayılan 30).
 */
class ExpiryTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public function test_stale_unstarted_record_expires_as_a_system_action(): void
    {
        // K-06: kayıt silinmez, "süresi doldu" olur; olay sistem işlemi olarak (actor NULL) yazılır.
        $machine = $this->makeMachine();
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $machine);
        $this->at('08:31:00');

        $expired = $this->workflow()->expireStale();

        $this->assertSame(1, $expired);
        $cleaning = $cleaning->fresh();
        $this->assertSame(CleaningStatus::Expired, $cleaning->status);
        $this->assertMoment('2026-10-09 08:31:00', $cleaning->closed_at);
        $this->assertNull($cleaning->started_at);
        $this->assertSame(StepStatus::Pending, $this->stepOf($cleaning, 1)->status);

        $event = $this->lastEvent($cleaning, 'cleaning.expired');
        $this->assertNull($event->actor_id);
        $this->assertMoment('2026-10-09 08:31:00', $event->occurred_at);
        $this->assertEquals(30, $event->payload['stale_after_minutes']);
        $this->assertSame(['cleaning.opened', 'cleaning.expired'], $this->eventTypes($cleaning));
        $this->assertEventChainIntact($cleaning);
    }

    public function test_record_expires_exactly_when_the_threshold_is_reached(): void
    {
        // created_at <= şimdi − 30 dk: 29:59'da değil, 30:00'da.
        $machine = $this->makeMachine();
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $machine);

        $this->at('08:29:59');
        $this->assertSame(0, $this->workflow()->expireStale());
        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);

        $this->at('08:30:00');
        $this->assertSame(1, $this->workflow()->expireStale());
        $this->assertSame(CleaningStatus::Expired, $cleaning->fresh()->status);
    }

    public function test_recent_unstarted_records_are_left_alone(): void
    {
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $old = $this->openCleaning($ahmet, $machine);
        $this->at('08:20:00');
        $recent = $this->openCleaning($ahmet, $machine);
        $this->at('08:35:00');

        $this->assertSame(1, $this->workflow()->expireStale());

        $this->assertSame(CleaningStatus::Expired, $old->fresh()->status);
        $this->assertSame(CleaningStatus::Created, $recent->fresh()->status);
        $this->assertSame(['cleaning.opened'], $this->eventTypes($recent));
    }

    public function test_started_records_are_never_expired_even_when_left_for_hours(): void
    {
        // R-31: yarım kalan temizlik otomatik kapatılmaz (ör. vardiya bitti).
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $running = $this->openCleaning($ahmet, $machine);
        $this->at('08:10:00');
        $this->workflow()->startStep($ahmet, $this->stepOf($running, 1));
        $this->at('09:00:00');
        $this->workflow()->pauseStep($ahmet, $this->stepOf($running, 1));
        $this->at('18:00:00');
        $events = $this->eventTypes($running);

        $this->assertSame(0, $this->workflow()->expireStale());

        $running = $running->fresh();
        $this->assertSame(CleaningStatus::InProgress, $running->status);
        $this->assertNull($running->closed_at);
        $this->assertSame($events, $this->eventTypes($running));

        $this->workflow()->resumeStep($ahmet, $this->stepOf($running, 1));
        $this->assertSame(StepStatus::Running, $this->stepOf($running, 1)->status, 'Kişi geldiğinde kaldığı yerden devam eder.');
    }

    public function test_closed_records_are_not_touched(): void
    {
        $machine = $this->makeMachine([['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $completed = $this->openCleaning($ahmet, $machine);
        $this->runStep($ahmet, $completed, 1);
        $cancelled = $this->openCleaning($ahmet, $machine);
        $this->workflow()->cancel($ahmet, $cancelled->fresh(), CancelReason::InvalidRecord, 'Yanlış açıldı');
        $this->at('10:00:00');
        $eventCount = CleaningEvent::count();

        $this->assertSame(0, $this->workflow()->expireStale());

        $this->assertSame(CleaningStatus::Completed, $completed->fresh()->status);
        $this->assertSame(CleaningStatus::Cancelled, $cancelled->fresh()->status);
        $this->assertSame($eventCount, CleaningEvent::count());
    }

    public function test_an_expired_record_is_not_expired_again(): void
    {
        $machine = $this->makeMachine();
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $machine);
        $this->at('08:40:00');
        $this->workflow()->expireStale();
        $this->at('09:40:00');

        $this->assertSame(0, $this->workflow()->expireStale());

        $this->assertSame(['cleaning.opened', 'cleaning.expired'], $this->eventTypes($cleaning));
        $this->assertMoment('2026-10-09 08:40:00', $cleaning->fresh()->closed_at);
    }

    public function test_threshold_comes_from_configuration(): void
    {
        // K-06: eşik yönetici tarafından değiştirilebilir.
        config(['cleaning.stale_after_minutes' => 10]);
        $machine = $this->makeMachine();
        $cleaning = $this->openCleaning($this->operator('Ahmet'), $machine);
        $this->at('08:10:00');

        $this->assertSame(1, $this->workflow()->expireStale());

        $this->assertSame(CleaningStatus::Expired, $cleaning->fresh()->status);
        $this->assertEquals(10, $this->lastEvent($cleaning, 'cleaning.expired')->payload['stale_after_minutes']);
    }

    public function test_expired_record_can_no_longer_be_started(): void
    {
        // K-06: süresi dolan kayıt kapanmıştır.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->at('08:45:00');
        $this->workflow()->expireStale();

        $this->assertRuleViolation('record_closed', fn () => $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1)));

        $this->assertSame(CleaningStatus::Expired, $cleaning->fresh()->status);
    }

    public function test_command_expires_stale_records(): void
    {
        // Sözleşme 1B: cleanings:expire-stale komutu (zamanlayıcı her dakika çalıştırır).
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $stale = $this->openCleaning($ahmet, $machine);
        $this->at('08:25:00');
        $recent = $this->openCleaning($ahmet, $machine);
        $this->at('08:40:00');

        $this->artisan('cleanings:expire-stale')->assertSuccessful();

        $this->assertSame(CleaningStatus::Expired, $stale->fresh()->status);
        $this->assertSame(CleaningStatus::Created, $recent->fresh()->status);
        $this->assertNull($this->lastEvent($stale, 'cleaning.expired')->actor_id);
    }
}
