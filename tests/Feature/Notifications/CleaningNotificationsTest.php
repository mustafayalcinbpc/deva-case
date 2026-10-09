<?php

namespace Tests\Feature\Notifications;

use App\Enums\CancelReason;
use App\Events\CleaningCancelled;
use App\Events\CleaningExpired;
use App\Events\PhaseCompletedBelowMinimum;
use App\Listeners\NotifyManagersOfBelowMinimumPhase;
use App\Listeners\NotifyOwnerOfCancellation;
use App\Listeners\NotifyOwnerOfExpiry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Cleaning\Concerns\BuildsCleaningFixtures;
use Tests\Feature\Cleaning\Concerns\InteractsWithCleaningWorkflow;
use Tests\TestCase;

/**
 * Domain olaylarını dinleyen kuyruk dinleyicileri ve gönderdikleri veritabanı bildirimleri.
 * Testlerde kuyruk `sync`tır: workflow çağrısından bildirim satırına kadar uçtan uca çalışır.
 */
class CleaningNotificationsTest extends TestCase
{
    use BuildsCleaningFixtures, InteractsWithCleaningWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('08:00:00');
    }

    public static function listeners(): iterable
    {
        yield 'minimum süre altı → yöneticiler' => [PhaseCompletedBelowMinimum::class, NotifyManagersOfBelowMinimumPhase::class];
        yield 'iptal → sahibi' => [CleaningCancelled::class, NotifyOwnerOfCancellation::class];
        yield 'süresi doldu → sahibi' => [CleaningExpired::class, NotifyOwnerOfExpiry::class];
    }

    #[DataProvider('listeners')]
    public function test_listeners_are_queued_and_wired_to_their_event(string $event, string $listener): void
    {
        $this->assertTrue(is_subclass_of($listener, ShouldQueue::class), "{$listener} kuyrukta çalışmalı.");

        Event::fake();
        Event::assertListening($event, $listener);
    }

    public function test_listener_jobs_go_to_the_queue_instead_of_running_inline(): void
    {
        Queue::fake();
        $ahmet = $this->operator('Ahmet');
        $manager = $this->manager('Ayşe');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());

        $this->workflow()->cancel($manager, $cleaning->fresh(), CancelReason::Other, 'Plan değişti');

        Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyOwnerOfCancellation::class
            && $job->data[0] instanceof CleaningCancelled
            && $job->data[0]->cleaningId === $cleaning->id);
        $this->assertSame(0, DatabaseNotification::count(), 'İş kuyrukta beklerken bildirim yazılmamalı.');
    }

    public function test_below_minimum_phase_notifies_every_active_manager(): void
    {
        // K-01
        $ayse = $this->manager('Ayşe');
        $fatma = $this->manager('Fatma');
        $former = $this->deactivate($this->manager('Eski Yönetici'));
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $machine = $this->makeMachine([['steps' => 1, 'min_seconds' => 900]]);
        $cleaning = $this->openCleaning($ahmet, $machine, [$mehmet]);

        $this->runStep($ahmet, $cleaning, 1, 600, 'Makine erken durduruldu');

        foreach ([$ayse, $fatma] as $manager) {
            $notification = $manager->notifications()->sole();
            $this->assertSame('cleaning.phase-below-minimum', $notification->type);
            $this->assertSame([
                'title' => 'Minimum süre altında faz',
                'message' => "{$cleaning->record_no}: \"Faz 1\" 10 dk 00 sn sürdü (minimum 15 dk 00 sn). Gerekçe: Makine erken durduruldu",
                'url' => route('cleanings.show', $cleaning, absolute: false),
                'level' => 'warning',
            ], $notification->data);
            $this->assertNull($notification->read_at);
        }

        $this->assertSame(0, $former->notifications()->count(), 'Pasif yöneticiye bildirim gitmez.');
        $this->assertSame(0, $ahmet->notifications()->count());
        $this->assertSame(0, $mehmet->notifications()->count());
    }

    public function test_phase_within_minimum_and_normal_completion_notify_nobody(): void
    {
        $this->manager('Ayşe');
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine([['steps' => 1, 'min_seconds' => 60]]));

        $this->runStep($ahmet, $cleaning, 1, 60);

        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_cancellation_by_a_manager_notifies_the_owner(): void
    {
        // K-08
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $manager = $this->manager('Ayşe Yılmaz');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), [$mehmet]);
        $this->workflow()->startStep($ahmet, $this->stepOf($cleaning, 1));

        $this->workflow()->cancel($manager, $cleaning->fresh(), CancelReason::PersonnelLeft, 'Operatör vardiyadan ayrıldı');

        $notification = $ahmet->notifications()->sole();
        $this->assertSame('cleaning.cancelled', $notification->type);
        $this->assertSame([
            'title' => 'Kaydınız iptal edildi',
            'message' => "{$cleaning->record_no} kaydı Ayşe Yılmaz tarafından iptal edildi. Gerekçe: Personel ayrıldı — Operatör vardiyadan ayrıldı",
            'url' => route('cleanings.show', $cleaning, absolute: false),
            'level' => 'danger',
        ], $notification->data);

        $this->assertSame(0, $manager->notifications()->count(), 'İptal eden kendine bildirim almaz.');
        $this->assertSame(0, $mehmet->notifications()->count(), 'Yalnızca sahip bilgilendirilir.');
    }

    public function test_owner_cancelling_own_record_is_not_notified_and_nothing_is_queued(): void
    {
        // K-09
        Queue::fake();
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());

        $this->workflow()->cancel($ahmet, $cleaning->fresh(), CancelReason::InvalidRecord, 'Yanlış makine');

        Queue::assertNotPushed(CallQueuedListener::class);
    }

    public function test_inactive_owner_is_not_notified_of_cancellation(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());
        $this->deactivate($ahmet);

        $this->workflow()->cancel($this->manager(), $cleaning->fresh(), CancelReason::PersonnelLeft, 'Ayrıldı');

        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_expiry_notifies_the_owner(): void
    {
        // K-06
        $ahmet = $this->operator('Ahmet');
        $mehmet = $this->operator('Mehmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine(), [$mehmet]);
        $this->at('08:30:00');

        $this->workflow()->expireStale();

        $notification = $ahmet->notifications()->sole();
        $this->assertSame('cleaning.expired', $notification->type);
        $this->assertSame([
            'title' => 'Kaydın süresi doldu',
            'message' => "{$cleaning->record_no}: 30 dakika içinde ilk adım başlatılmadığı için kayıt kapatıldı.",
            'url' => route('cleanings.show', $cleaning, absolute: false),
            'level' => 'info',
        ], $notification->data);
        $this->assertSame(0, $mehmet->notifications()->count());
    }

    public function test_listeners_tolerate_missing_records_and_users(): void
    {
        $ahmet = $this->operator('Ahmet');
        $this->manager('Ayşe');
        $cleaning = $this->makeCleaningRecord($this->makeMachine(), $ahmet);
        $missing = 999_999;

        (new NotifyOwnerOfExpiry)->handle(new CleaningExpired($missing, $ahmet->id, 30));
        (new NotifyOwnerOfExpiry)->handle(new CleaningExpired($cleaning->id, $missing, 30));
        (new NotifyOwnerOfCancellation)->handle(new CleaningCancelled($missing, $ahmet->id, $missing, CancelReason::Other, 'x'));
        (new NotifyOwnerOfCancellation)->handle(new CleaningCancelled($cleaning->id, $missing, $ahmet->id, CancelReason::Other, 'x'));
        (new NotifyManagersOfBelowMinimumPhase)->handle(new PhaseCompletedBelowMinimum($cleaning->id, $missing, $ahmet->id, 10, 60));
        (new NotifyManagersOfBelowMinimumPhase)->handle(new PhaseCompletedBelowMinimum($missing, $missing, $ahmet->id, 10, 60));

        $this->assertSame(0, DatabaseNotification::count());
    }

    public function test_cancellation_message_survives_a_missing_canceller(): void
    {
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->makeCleaningRecord($this->makeMachine(), $ahmet);

        (new NotifyOwnerOfCancellation)->handle(new CleaningCancelled($cleaning->id, $ahmet->id, 999_999, CancelReason::Other, 'Not'));

        $this->assertSame(
            "{$cleaning->record_no} kaydı başka bir kullanıcı tarafından iptal edildi. Gerekçe: Diğer — Not",
            $ahmet->notifications()->sole()->data['message'],
        );
    }

    public function test_cancellation_through_the_screen_reaches_the_owners_bell(): void
    {
        // Uçtan uca: yönetici formdan iptal eder, sahip zilde görür ve bildirimi açınca kayda gider.
        $ahmet = $this->operator('Ahmet');
        $cleaning = $this->openCleaning($ahmet, $this->makeMachine());

        $this->actingAs($this->manager('Ayşe'))
            ->post(route('cleanings.cancel', $cleaning), ['cancel_reason' => 'other', 'cancel_note' => 'Hat kapatıldı'])
            ->assertRedirect(route('cleanings.show', $cleaning));

        $this->actingAs($ahmet)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('<span class="badge navbar-badge notification-bell__count">1</span>', false)
            ->assertSee('Kaydınız iptal edildi')
            ->assertSee("{$cleaning->record_no} kaydı Ayşe tarafından iptal edildi");

        $this->get(route('notifications.open', $ahmet->notifications()->sole()))
            ->assertRedirect(route('cleanings.show', $cleaning, absolute: false));
        $this->assertNotNull($ahmet->notifications()->sole()->read_at);
    }

    public function test_each_expired_record_notifies_with_its_own_record_number(): void
    {
        $ahmet = $this->operator('Ahmet');
        $first = $this->openCleaning($ahmet, $this->makeMachine(code: 'M01'));
        $second = $this->openCleaning($ahmet, $this->makeMachine(code: 'M02'));
        $this->at('08:30:00');

        $this->workflow()->expireStale();

        $this->assertEqualsCanonicalizing([
            "{$first->record_no}: 30 dakika içinde ilk adım başlatılmadığı için kayıt kapatıldı.",
            "{$second->record_no}: 30 dakika içinde ilk adım başlatılmadığı için kayıt kapatıldı.",
        ], $ahmet->notifications()->get()->pluck('data.message')->all());
    }
}
