<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Nocturne kabuğu: tema ilk boyamadan önce uygulanır, tema düğmesi her iki düzende de vardır,
 * sidebar alt kısmında kullanıcı bloğu, üst barda menüden üretilen breadcrumb bulunur.
 */
class ThemeShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_theme_is_applied_in_head_before_styles_on_both_layouts(): void
    {
        $guest = $this->get(route('login'))->assertOk();
        $app = $this->actingAs(User::factory()->create(['name' => 'Ayşe Demir']))->get(route('dashboard'))->assertOk();

        foreach ([$guest, $app] as $response) {
            $head = $this->page($response)->head->innerHTML;
            $script = strpos($head, "localStorage.getItem('app-theme')");

            $this->assertNotFalse($script, 'Tema betiği <head> içinde olmalı.');
            $this->assertStringContainsString('prefers-color-scheme: dark', $head);
            $before = substr($head, 0, $script);
            $this->assertStringNotContainsString('<link', $before, 'Tema stil dosyalarından önce uygulanmalı.');
            $this->assertStringNotContainsString('<style', $before, 'Tema stil dosyalarından önce uygulanmalı.');
        }
    }

    public function test_theme_toggle_has_accessible_label_on_both_layouts(): void
    {
        $guest = $this->get(route('login'));
        $app = $this->actingAs(User::factory()->create())->get(route('cleanings.index'));

        foreach ([$guest, $app] as $response) {
            $toggle = $this->page($response)->querySelector('button.theme-toggle[data-module="theme-toggle"]');

            $this->assertNotNull($toggle);
            $this->assertSame('button', $toggle->getAttribute('type'));
            $this->assertSame('Koyu tema', $toggle->getAttribute('aria-label'));
            $this->assertNotNull($toggle->querySelector('.bi-moon'));
            $this->assertNotNull($toggle->querySelector('.bi-sun'));
        }
    }

    public function test_sidebar_has_user_block_with_initials_role_and_logout(): void
    {
        $user = User::factory()->manager()->create(['name' => 'Zeynep Nur Arslan']);

        $page = $this->page($this->actingAs($user)->get(route('dashboard')));
        $block = $page->querySelector('.app-sidebar .sidebar-user');

        $this->assertNotNull($block);
        $this->assertSame('ZA', trim($block->querySelector('.avatar')->textContent));
        $this->assertSame('Zeynep Nur Arslan', trim($block->querySelector('.sidebar-user__name')->textContent));
        $this->assertSame($user->role->label(), trim($block->querySelector('.sidebar-user__role')->textContent));
        $this->assertSame(route('logout'), $block->querySelector('form')->getAttribute('action'));
        $this->assertSame('post', strtolower($block->querySelector('form')->getAttribute('method')));
        $this->assertNull($page->querySelector('.app-sidebar[data-bs-theme]'), 'Sidebar temadan bağımsız koyu olmamalı.');
    }

    public function test_breadcrumb_follows_the_active_menu_item(): void
    {
        config(['menu' => [
            ['label' => 'Gösterge Paneli', 'icon' => 'bi-speedometer2', 'route' => 'dashboard'],
            ['group' => 'Kayıtlar', 'icon' => 'bi-list', 'items' => [
                ['label' => 'Kayıt listesi', 'icon' => 'bi-list', 'route' => 'cleanings.index', 'active' => 'cleanings.*'],
            ]],
        ]]);

        $this->actingAs(User::factory()->create());

        $this->assertSame(
            ['Dijital Temizlik', 'Kayıtlar', 'Kayıt listesi', 'Yeni Kayıt'],
            $this->breadcrumb($this->get(route('cleanings.create'))),
        );

        // Etkin öğenin adı sayfa başlığıyla aynıysa tekrar edilmez.
        $this->assertSame(
            ['Dijital Temizlik', 'Gösterge Paneli'],
            $this->breadcrumb($this->get(route('dashboard'))),
        );

        $current = $this->page($this->get(route('cleanings.create')))->querySelector('.app-breadcrumb .breadcrumb-item:last-child');
        $this->assertSame('page', $current->getAttribute('aria-current'));
    }

    public function test_page_header_shows_subtitle_and_actions(): void
    {
        $this->actingAs(User::factory()->create());

        $page = $this->page($this->get(route('cleanings.create')));

        $this->assertStringContainsString('Kayıt açmak temizliği başlatmaz', $page->querySelector('.app-page-subtitle')->textContent);
        $this->assertSame('cleaning-form', $page->querySelector('.app-page-actions button[type="submit"]')->getAttribute('form'));
        $this->assertNotNull($page->getElementById('cleaning-form'));
    }

    private function page(TestResponse $response): HTMLDocument
    {
        return HTMLDocument::createFromString($response->getContent(), LIBXML_NOERROR);
    }

    /** @return list<string> */
    private function breadcrumb(TestResponse $response): array
    {
        return array_map(
            fn ($item) => trim(preg_replace('/\s+/u', ' ', $item->textContent)),
            iterator_to_array($this->page($response)->querySelectorAll('.app-breadcrumb .breadcrumb-item')),
        );
    }
}
