<?php

namespace Tests\Feature\Notifications;

use App\Enums\CancelReason;
use App\Enums\CleaningStatus;
use App\Events\CleaningCancelled;
use App\Events\CleaningCompleted;
use App\Events\CleaningExpired;
use App\Events\PhaseCompletedBelowMinimum;
use Closure;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * CleaningWorkflow'un yayımladığı domain olayları: her geçişte doğru olay ve kimlikler; kural
 * ihlalinde ya da geri alınan transaction'da hiçbir olay (ShouldDispatchAfterCommit).
 */
class DomainEventsTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    private const DOMAIN_EVENTS = [
        PhaseCompletedBelowMinimum::class,
        CleaningCompleted::class,
        CleaningCancelled::class,
        CleaningExpired::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');

        // Yalnızca domain olayları sahte; model olayları (değişmezlik koruması) çalışmaya devam eder.
        Event::fake(self::DOMAIN_EVENTS);
    }

    public static function domainEvents(): iterable
    {
        foreach (self::DOMAIN_EVENTS as $class) {
            yield class_basename($class) => [$class];
        }
    }

    #[DataProvider('domainEvents')]
    public function test_events_are_dispatched_after_commit_and_carry_only_scalars(string $class): void
    {
        $this->assertTrue(is_subclass_of($class, ShouldDispatchAfterCommit::class));

        foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
            $type = $parameter->getType()->getName();
            $this->assertTrue(in_array($type, ['int', 'string'], true) || enum_exists($type), "{$class}::\${$parameter->getName()} model değil kimlik ya da değer taşımalı ({$type}).");
        }
    }

    public function test_phase_below_minimum_and_completion_are_dispatched(): void
    {
        // K-01: 15 dk minimum, 10 dk çalışıldı, gerekçeyle kapandı; tek faz olduğu için kayıt da tamamlanır.
        $machine = $this->makeMachine([['steps' => 1, 'min_seconds' => 900]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);

        $this->runStep($ahmet, $cleaning, 1, 600, 'Makine erken durduruldu');

        Event::assertDispatchedTimes(PhaseCompletedBelowMinimum::class, 1);
        Event::assertDispatched(PhaseCompletedBelowMinimum::class, fn (PhaseCompletedBelowMinimum $e) => $e->cleaningId === $cleaning->id
            && $e->phaseId === $this->phaseOf($cleaning, 1)->id
            && $e->actorId === $ahmet->id
            && $e->measuredSeconds === 600
            && $e->minimumSeconds === 900);

        Event::assertDispatchedTimes(CleaningCompleted::class, 1);
        Event::assertDispatched(CleaningCompleted::class, fn (CleaningCompleted $e) => $e->cleaningId === $cleaning->id
            && $e->ownerId === $ahmet->id
            && $e->actorId === $ahmet->id);
    }

    public function test_completion_is_dispatched_once_after_the_last_step_and_phase_within_minimum_is_silent(): void
    {
        $machine = $this->makeMachine([['steps' => 2, 'min_seconds' => 60], ['steps' => 1]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);

        $this->runStep($ahmet, $cleaning, 1, 60);
        $this->runStep($ahmet, $cleaning, 2, 60);
        Event::assertNotDispatched(CleaningCompleted::class);

        $this->runStep($ahmet, $cleaning, 3, 60);

        Event::assertDispatchedTimes(CleaningCompleted::class, 1);
        Event::assertNotDispatched(PhaseCompletedBelowMinimum::class);
    }

    public function test_below_minimum_without_reason_dispatches_nothing(): void
    {
        // Kural ihlali (K-01): transaction geri alınır, olay da çıkmaz.
        $machine = $this->makeMachine([['steps' => 1, 'min_seconds' => 900]]);
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));
        $this->travel(600)->seconds();

        $this->assertRuleViolation('below_minimum_duration', fn () => $this->workflow()->completeStep($ahmet, $this->stepOf($cleaning, 1)));

        Event::assertNothingDispatched();
    }

    public function test_cancellation_carries_who_and_why(): void
    {
        // K-08: yönetici başlamış kaydı iptal eder.
        $machine = $this->makeMachine();
        $ahmet = $this->operator('Ahmet');
        $manager = $this->manager('Ayşe');
        $cleaning = $this->openCleaning($ahmet, $machine);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->workflow()->cancel($manager, $cleaning->fresh(), CancelReason::PersonnelLeft, 'Vardiya bitti');

        Event::assertDispatchedTimes(CleaningCancelled::class, 1);
        Event::assertDispatched(CleaningCancelled::class, fn (CleaningCancelled $e) => $e->cleaningId === $cleaning->id
            && $e->ownerId === $ahmet->id
            && $e->cancelledById === $manager->id
            && $e->reason === CancelReason::PersonnelLeft
            && $e->note === 'Vardiya bitti'
            && ! $e->cancelledByOwner());
    }

    public function test_owner_cancelling_own_record_is_also_an_event(): void
    {
        // K-09: olay her iptalde çıkar; bildirimi kime göndereceğine dinleyici karar verir.
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());

        $this->workflow()->cancel($ahmet, $cleaning->fresh(), CancelReason::InvalidRecord, 'Yanlış makine');

        Event::assertDispatched(CleaningCancelled::class, fn (CleaningCancelled $e) => $e->cancelledByOwner());
    }

    public function test_rejected_cancellation_dispatches_nothing(): void
    {
        // K-09: başlamış kaydı sahibi iptal edemez.
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->assertRuleViolation('not_allowed', fn () => $this->workflow()->cancel($ahmet, $cleaning->fresh(), CancelReason::InvalidRecord, 'Vazgeçtim'));
        $this->assertRuleViolation('reason_required', fn () => $this->workflow()->cancel($this->manager(), $cleaning->fresh(), CancelReason::Other, '   '));

        Event::assertNothingDispatched();
    }

    public function test_expiry_is_dispatched_per_expired_record(): void
    {
        // K-06
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $stale = $this->openCleaning($ahmet, $this->makeMachine(code: 'M01'));
        $this->at('08:20:00');
        $fresh = $this->openCleaning($mehmet, $this->makeMachine(code: 'M02'));
        $this->at('08:30:00');

        $this->assertSame(1, $this->workflow()->expireStale());

        Event::assertDispatchedTimes(CleaningExpired::class, 1);
        Event::assertDispatched(CleaningExpired::class, fn (CleaningExpired $e) => $e->cleaningId === $stale->id
            && $e->ownerId === $ahmet->id
            && $e->staleAfterMinutes === 30);
        $this->assertSame(CleaningStatus::Created, $fresh->fresh()->status);
    }

    public function test_events_are_dropped_when_the_surrounding_transaction_rolls_back(): void
    {
        // Olay işlem içinde üretilir ama commit'e kadar bekletilir; geri alınırsa hiç çıkmaz.
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $this->at('08:31:00');

        $this->insideRolledBackTransaction(function () use ($cleaning) {
            $this->workflow()->cancel($this->manager(), $cleaning->fresh(), CancelReason::Other, 'Deneme');
        });
        $this->insideRolledBackTransaction(function () {
            $this->assertSame(1, $this->workflow()->expireStale());
        });

        Event::assertNothingDispatched();
        $this->assertSame(CleaningStatus::Created, $cleaning->fresh()->status);
    }

    private function insideRolledBackTransaction(Closure $work): void
    {
        try {
            DB::transaction(function () use ($work) {
                $work();

                throw new RuntimeException('geri al');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('geri al', $e->getMessage());
        }
    }
}
