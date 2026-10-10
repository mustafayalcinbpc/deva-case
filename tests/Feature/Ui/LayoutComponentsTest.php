<?php

namespace Tests\Feature\Ui;

use App\Enums\CleaningStatus;
use App\Enums\StepStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_badge_renders_label_and_theme_modifier(): void
    {
        $this->blade('<x-status-badge :status="$status" />', ['status' => CleaningStatus::InProgress])
            ->assertSee('status-badge status-badge--in-progress', false)
            ->assertSee('Devam ediyor');

        $this->blade('<x-status-badge :status="$status" class="ms-2" />', ['status' => StepStatus::Paused])
            ->assertSee('status-badge status-badge--paused ms-2', false)
            ->assertSee('Duraklatıldı');
    }

    public function test_status_badge_modifiers_cover_definition_states(): void
    {
        // Tanım ekranları durum rozetini doğrudan sınıfla yazar; tema bu değiştiricileri tanır.
        $css = file_get_contents(resource_path('scss/theme/_components.scss'));

        foreach (['active', 'live', 'draft', 'retired', 'inactive', 'superseded', 'scheduled'] as $modifier) {
            $this->assertMatchesRegularExpression("/&--{$modifier}\\b/", $css, "status-badge--{$modifier} tanımlı olmalı.");
        }
    }

    public function test_datetime_is_shown_in_display_timezone(): void
    {
        $utc = CarbonImmutable::parse('2026-10-09 05:15:00', 'UTC');

        $this->blade('<x-datetime :value="$value" />', ['value' => $utc])
            ->assertSee('datetime="2026-10-09T05:15:00+00:00"', false)
            ->assertSee('09.10.2026 08:15');

        $this->blade('<x-datetime :value="null" />')->assertSee('—');
    }

    public function test_duration_is_human_readable(): void
    {
        $this->blade('<x-duration :seconds="45" />')->assertSee('45 sn');
        $this->blade('<x-duration :seconds="750" />')->assertSee('12 dk 30 sn');
        $this->blade('<x-duration :seconds="3900" />')->assertSee('1 sa 05 dk');
    }

    public function test_sidebar_shows_items_and_groups_by_role(): void
    {
        config(['menu' => [
            ['label' => 'Gösterge Paneli', 'icon' => 'bi-speedometer2', 'route' => 'dashboard'],
            ['group' => 'Yönetim', 'icon' => 'bi-shield-lock', 'roles' => ['manager'], 'items' => [
                ['label' => 'Kullanıcı listesi', 'icon' => 'bi-people', 'route' => 'dashboard'],
            ]],
            ['group' => 'Temizlik', 'icon' => 'bi-droplet-half', 'items' => [
                ['label' => 'Kayıt listesi', 'icon' => 'bi-list', 'route' => 'cleanings.index'],
                ['label' => 'Yalnızca yönetici', 'icon' => 'bi-gear', 'route' => 'dashboard', 'roles' => ['manager']],
            ]],
        ]]);

        $this->actingAs(User::factory()->create());
        $this->blade('<x-sidebar-menu />')
            ->assertSee('Gösterge Paneli')
            ->assertSee('Temizlik')
            ->assertSee('Kayıt listesi')
            ->assertDontSee('Yönetim')
            ->assertDontSee('Kullanıcı listesi')
            ->assertDontSee('Yalnızca yönetici');

        $this->actingAs(User::factory()->manager()->create());
        $this->blade('<x-sidebar-menu />')
            ->assertSee('Yönetim')
            ->assertSee('Kullanıcı listesi')
            ->assertSee('Yalnızca yönetici');
    }

    public function test_group_is_a_collapsible_toggle_with_its_items_below(): void
    {
        config(['menu' => [
            ['group' => 'Raporlar', 'icon' => 'bi-bar-chart-line', 'items' => [
                ['label' => 'Süre', 'icon' => 'bi-stopwatch', 'route' => 'dashboard'],
                ['label' => 'Kayıtlar', 'icon' => 'bi-list', 'route' => 'cleanings.index'],
            ]],
        ]]);
        $this->actingAs(User::factory()->manager()->create());

        $html = (string) $this->blade('<x-sidebar-menu />');
        $page = HTMLDocument::createFromString('<!doctype html><html><body>'.$html.'</body></html>', LIBXML_NOERROR);

        $this->assertSame('treeview', $page->querySelector('ul.sidebar-menu')->getAttribute('data-lte-toggle'));
        $group = $page->querySelector('ul.sidebar-menu > li.nav-group');
        $toggle = $group->querySelector('button.nav-link');
        $this->assertSame('Raporlar', trim($toggle->querySelector('p')->firstChild->textContent));
        $this->assertNotNull($toggle->querySelector('.nav-arrow'));
        $this->assertSame('menu-group-0', $toggle->getAttribute('aria-controls'));
        $this->assertSame(
            ['Süre', 'Kayıtlar'],
            array_map(fn ($link) => trim($link->textContent), iterator_to_array($group->querySelectorAll('#menu-group-0.nav-treeview > li > a.nav-link'))),
        );
        // Etkin öğe bu grupta değil: grup kapalı gelir.
        $this->assertFalse($group->classList->contains('menu-open'));
        $this->assertSame('false', $toggle->getAttribute('aria-expanded'));
    }

    public function test_group_of_the_current_page_is_open(): void
    {
        config(['menu' => [
            ['label' => 'Gösterge Paneli', 'icon' => 'bi-speedometer2', 'route' => 'dashboard'],
            ['group' => 'Temizlik', 'icon' => 'bi-droplet-half', 'items' => [
                ['label' => 'Kayıt listesi', 'icon' => 'bi-list', 'route' => 'cleanings.index', 'active' => 'cleanings.*'],
            ]],
        ]]);
        $this->actingAs(User::factory()->create());

        $page = HTMLDocument::createFromString($this->get(route('cleanings.create'))->getContent(), LIBXML_NOERROR);

        $group = $page->querySelector('.sidebar-menu li.nav-group');
        $this->assertTrue($group->classList->contains('menu-open'));
        $this->assertSame('true', $group->querySelector('button.nav-link')->getAttribute('aria-expanded'));
        $this->assertTrue($group->querySelector('button.nav-link')->classList->contains('nav-group__toggle--current'));
        $this->assertSame('page', $group->querySelector('.nav-treeview a.nav-link.active')->getAttribute('aria-current'));
    }

    public function test_items_whose_route_is_not_defined_are_hidden(): void
    {
        config(['menu' => [
            ['label' => 'Gösterge Paneli', 'icon' => 'bi-speedometer2', 'route' => 'dashboard'],
            ['label' => 'Henüz yok', 'icon' => 'bi-gear', 'route' => 'reports.not-yet-defined'],
            ['group' => 'Raporlar', 'icon' => 'bi-bar-chart-line', 'items' => [
                ['label' => 'Bu da yok', 'icon' => 'bi-gear', 'route' => 'reports.not-yet-defined'],
            ]],
        ]]);

        $this->actingAs(User::factory()->manager()->create());

        $this->blade('<x-sidebar-menu />')
            ->assertSee('Gösterge Paneli')
            ->assertDontSee('Henüz yok')
            ->assertDontSee('Bu da yok')
            ->assertDontSee('Raporlar');
    }

    public function test_group_without_visible_items_is_hidden(): void
    {
        config(['menu' => [
            ['group' => 'Boş grup', 'icon' => 'bi-folder', 'items' => [
                ['label' => 'Yalnızca yönetici', 'icon' => 'bi-gear', 'route' => 'dashboard', 'roles' => ['manager']],
            ]],
        ]]);

        $this->actingAs(User::factory()->create());

        $this->blade('<x-sidebar-menu />')->assertDontSee('Boş grup');
    }

    public function test_application_menu_groups_are_meaningful_for_each_role(): void
    {
        // Operatör yalnızca sahadaki işi görür; yönetici planlama, tanımlar, raporlar ve yönetim gruplarını da.
        $this->actingAs(User::factory()->create());
        $this->assertSame(['Gösterge Paneli', 'Temizlik'], $this->topLevelLabels());

        $this->actingAs(User::factory()->manager()->create());
        $this->assertSame(['Gösterge Paneli', 'Temizlik', 'Planlama', 'Tanımlar', 'Raporlar', 'Yönetim'], $this->topLevelLabels());
    }

    /**
     * @return list<string>
     */
    private function topLevelLabels(): array
    {
        $html = (string) $this->blade('<x-sidebar-menu />');
        $page = HTMLDocument::createFromString('<!doctype html><html><body>'.$html.'</body></html>', LIBXML_NOERROR);

        return array_map(
            fn ($link) => trim($link->querySelector('p')->firstChild->textContent),
            iterator_to_array($page->querySelectorAll('ul.sidebar-menu > li > .nav-link')),
        );
    }
}
