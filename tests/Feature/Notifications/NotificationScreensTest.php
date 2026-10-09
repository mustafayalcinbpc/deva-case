<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Bildirim zili, bildirim listesi, açma ve tümünü okundu işaretleme. Kullanıcı yalnızca kendi
 * bildirimlerini görür ve değiştirir; başkasının bildirimi 404 döner.
 */
class NotificationScreensTest extends TestCase
{
    use RefreshDatabase;

    private const ITEM_IN_LIST = 'class="list-group-item list-group-item-action notification-item';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-09 08:00:00'));
    }

    public function test_bell_shows_the_unread_count_and_the_latest_five(): void
    {
        $ahmet = User::factory()->create();
        foreach (range(1, 7) as $i) {
            $this->notify($ahmet, "Bildirim {$i}", ['level' => $i === 7 ? 'warning' : 'info'], read: $i <= 2);
            $this->travel(1)->minutes();
        }
        $this->notify(User::factory()->create(), 'Başkasının bildirimi');
        $this->travel(4)->minutes();

        $view = $this->actingAs($ahmet)->blade('<x-notification-bell />');

        $view->assertSee('<span class="badge navbar-badge notification-bell__count">5</span>', false)
            ->assertSee('aria-label="Bildirimler (5 okunmamış)"', false)
            ->assertSee('5 okunmamış bildirim')
            ->assertSeeInOrder(['Bildirim 7', 'Bildirim 6', 'Bildirim 5', 'Bildirim 4', 'Bildirim 3'])
            ->assertDontSee('Bildirim 2')
            ->assertDontSee('Başkasının bildirimi')
            ->assertSee('Bildirim 7 mesajı')
            ->assertSee('5 dakika önce')
            ->assertSee('notification-item--warning', false)
            ->assertSee('bi-exclamation-triangle', false)
            ->assertSee(route('notifications.open', $ahmet->notifications()->first()), false)
            ->assertSee('action="'.route('notifications.read-all').'"', false)
            ->assertSee('Tümünü okundu işaretle')
            ->assertSee('href="'.route('notifications.index').'"', false);
    }

    public function test_bell_without_unread_notifications_has_no_badge_or_read_all(): void
    {
        $ahmet = User::factory()->create();

        $this->actingAs($ahmet)->blade('<x-notification-bell />')
            ->assertDontSee('notification-bell__count', false)
            ->assertSee('Okunmamış bildirim yok')
            ->assertSee('Henüz bildirim yok.')
            ->assertDontSee('Tümünü okundu işaretle');

        $this->notify($ahmet, 'Eski bildirim', read: true);

        $this->actingAs($ahmet)->blade('<x-notification-bell />')
            ->assertDontSee('notification-bell__count', false)
            ->assertSee('Eski bildirim')
            ->assertDontSee('notification-item--unread', false);
    }

    public function test_bell_runs_two_queries_however_many_notifications_there_are(): void
    {
        $ahmet = User::factory()->create();
        foreach (range(1, 12) as $i) {
            $this->notify($ahmet, "Bildirim {$i}", read: $i % 2 === 0);
        }
        $this->actingAs($ahmet);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->blade('<x-notification-bell />');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(2, $queries, 'Okunmamış sayısı ve son beş bildirim: iki sorgu.');
    }

    public function test_bell_is_in_the_page_header(): void
    {
        $ahmet = User::factory()->create();
        $this->notify($ahmet, 'Yeni bildirim');

        $this->actingAs($ahmet)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('notification-bell', false)
            ->assertSee('<span class="badge navbar-badge notification-bell__count">1</span>', false);
    }

    public function test_opening_marks_the_notification_read_and_follows_its_link(): void
    {
        $ahmet = User::factory()->create();
        $notification = $this->notify($ahmet, 'Kayıt iptal', ['url' => '/cleanings/42']);
        $this->travel(5)->minutes();

        $this->actingAs($ahmet)->get(route('notifications.open', $notification))->assertRedirect('/cleanings/42');

        $this->assertSame('2026-10-09 08:05:00', $notification->fresh()->read_at->format('Y-m-d H:i:s'));

        // Okunmuş bildirimi tekrar açmak ilk okunma zamanını değiştirmez.
        $this->travel(5)->minutes();
        $this->get(route('notifications.open', $notification))->assertRedirect('/cleanings/42');
        $this->assertSame('2026-10-09 08:05:00', $notification->fresh()->read_at->format('Y-m-d H:i:s'));
    }

    public static function urlsThatFallBackToTheList(): iterable
    {
        yield 'bağlantı yok' => [null];
        yield 'boş' => [''];
        yield 'başka site' => ['https://evil.example/login'];
        yield 'protokolsüz başka site' => ['//evil.example/login'];
        yield 'ters bölü hilesi' => ['/\\evil.example'];
        yield 'javascript' => ['javascript:alert(1)'];
    }

    #[DataProvider('urlsThatFallBackToTheList')]
    public function test_opening_without_a_safe_link_goes_to_the_list(?string $url): void
    {
        $ahmet = User::factory()->create();
        $notification = $this->notify($ahmet, 'Bilgi', ['url' => $url]);

        $this->actingAs($ahmet)->get(route('notifications.open', $notification))
            ->assertRedirect(route('notifications.index'));

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_absolute_link_to_the_application_itself_is_followed(): void
    {
        // Ajan D'nin dışa aktarma bildirimi gibi tam adresli bağlantılar.
        $ahmet = User::factory()->create();
        $url = url('/reports/exports/7');
        $notification = $this->notify($ahmet, 'Dışa aktarma hazır', ['url' => $url]);

        $this->actingAs($ahmet)->get(route('notifications.open', $notification))->assertRedirect($url);
    }

    public function test_users_cannot_open_someone_elses_notification(): void
    {
        $ahmet = User::factory()->create();
        $mehmet = User::factory()->create();
        $notification = $this->notify($mehmet, 'Mehmet’in bildirimi');

        $this->actingAs($ahmet)->get(route('notifications.open', $notification))->assertNotFound();
        $this->get('/notifications/'.Str::uuid().'/open')->assertNotFound();
        $this->get('/notifications/not-a-uuid/open')->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_read_all_marks_only_the_users_own_notifications(): void
    {
        $ahmet = User::factory()->create();
        $mehmet = User::factory()->create();
        $this->notify($ahmet, 'Bir');
        $this->notify($ahmet, 'İki');
        $earlier = $this->notify($ahmet, 'Önceden okunmuş', read: true);
        $others = $this->notify($mehmet, 'Mehmet’in bildirimi');
        $this->travel(10)->minutes();

        $this->actingAs($ahmet)
            ->from(route('notifications.index'))
            ->post(route('notifications.read-all'))
            ->assertRedirect(route('notifications.index'))
            ->assertSessionHas('status', 'Bütün bildirimler okundu olarak işaretlendi.');

        $this->assertSame(0, $ahmet->unreadNotifications()->count());
        $this->assertSame('2026-10-09 08:00:00', $earlier->fresh()->read_at->format('Y-m-d H:i:s'), 'Okunmuş olanın zamanı değişmez.');
        $this->assertNull($others->fresh()->read_at);
    }

    public function test_index_lists_own_notifications_with_read_filter(): void
    {
        $ahmet = User::factory()->create();
        $this->notify($ahmet, 'Okunmamış A');
        $this->notify($ahmet, 'Okunmamış B', ['level' => 'danger']);
        $this->notify($ahmet, 'Okunmuş C', read: true);
        $this->notify(User::factory()->create(), 'Başkasının bildirimi');
        $this->actingAs($ahmet);

        $all = $this->get(route('notifications.index'))->assertOk();
        $this->assertSame(3, substr_count($all->getContent(), self::ITEM_IN_LIST));
        $all->assertSee(['Okunmamış A', 'Okunmamış B', 'Okunmuş C'])
            ->assertDontSee('Başkasının bildirimi')
            ->assertSee('notification-item--danger', false);
        $this->assertActiveTab('Tümü', $all->getContent());

        $unread = $this->get(route('notifications.index', ['filter' => 'unread']))->assertOk();
        $this->assertSame(2, substr_count($unread->getContent(), self::ITEM_IN_LIST));
        $this->assertActiveTab('Okunmamış', $unread->getContent());

        $read = $this->get(route('notifications.index', ['filter' => 'read']))->assertOk();
        $this->assertSame(1, substr_count($read->getContent(), self::ITEM_IN_LIST));
        $this->assertStringContainsString('Okunmuş C', $read->getContent());

        $unknown = $this->get(route('notifications.index', ['filter' => 'herhangi']))->assertOk();
        $this->assertSame(3, substr_count($unknown->getContent(), self::ITEM_IN_LIST));
        $this->assertActiveTab('Tümü', $unknown->getContent());
        $this->get(route('notifications.index', ['filter' => ['dizi']]))->assertOk();
    }

    public function test_index_paginates_and_keeps_the_filter(): void
    {
        $ahmet = User::factory()->create();
        foreach (range(1, 23) as $i) {
            $this->notify($ahmet, "Bildirim {$i}");
            $this->travel(1)->minutes();
        }
        $this->actingAs($ahmet);

        $first = $this->get(route('notifications.index', ['filter' => 'unread']))->assertOk();
        $this->assertSame(20, substr_count($first->getContent(), self::ITEM_IN_LIST));
        $first->assertSee('filter=unread&amp;page=2', false);

        $second = $this->get(route('notifications.index', ['filter' => 'unread', 'page' => 2]))->assertOk();
        $this->assertSame(3, substr_count($second->getContent(), self::ITEM_IN_LIST));
        $second->assertSeeInOrder(['Bildirim 3', 'Bildirim 2', 'Bildirim 1']);
    }

    public function test_index_empty_states(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('notifications.index'))->assertOk()->assertSee('Henüz bildirim yok.');
        $this->get(route('notifications.index', ['filter' => 'unread']))->assertOk()->assertSee('Okunmamış bildirim yok.');
        $this->get(route('notifications.index', ['filter' => 'read']))->assertOk()->assertSee('Okunmuş bildirim yok.');
    }

    public function test_guests_are_sent_to_login(): void
    {
        $notification = $this->notify(User::factory()->create(), 'Bilgi');

        $this->get(route('notifications.index'))->assertRedirect(route('login'));
        $this->get(route('notifications.open', $notification))->assertRedirect(route('login'));
        $this->post(route('notifications.read-all'))->assertRedirect(route('login'));
        $this->assertNull($notification->fresh()->read_at);
    }

    private function assertActiveTab(string $label, string $html): void
    {
        $this->assertMatchesRegularExpression('#class="nav-link active"\s+aria-current="page"\s*>'.preg_quote($label).'</a>#u', $html);
        $this->assertSame(1, substr_count($html, 'class="nav-link active"'), 'Tek sekme etkin olmalı.');
    }

    /**
     * Sözleşmedeki biçimde (title, message, url, level) doğrudan bir bildirim satırı yazar.
     *
     * @param  array<string, mixed>  $data
     */
    private function notify(User $user, string $title, array $data = [], bool $read = false): DatabaseNotification
    {
        return $user->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'data' => ['title' => $title, 'message' => "{$title} mesajı", 'url' => '/cleanings/1', 'level' => 'info', ...$data],
            'read_at' => $read ? now() : null,
        ]);
    }
}
