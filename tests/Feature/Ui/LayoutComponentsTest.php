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

    public function test_list_format_is_day_month_and_time_with_the_year_only_when_it_differs(): void
    {
        // Listelerde "10 Ekim 16:34"; tam zaman üzerine gelince (title) görünür.
        $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));

        $this->blade('<x-datetime :value="$value" format="list" />', ['value' => CarbonImmutable::parse('2026-10-10 13:34:07', 'UTC')])
            ->assertSee('>10 Ekim 16:34</time>', false)
            ->assertSee('title="10.10.2026 16:34:07"', false)
            ->assertSee('datetime="2026-10-10T13:34:07+00:00"', false);

        // Başka yıldan: yıl yazılır. Yerel saatle yılbaşı geçmiş olabilir (UTC 31 Aralık 22:30 = İstanbul 1 Ocak).
        $this->blade('<x-datetime :value="$value" format="list" />', ['value' => CarbonImmutable::parse('2025-03-03 06:12:00', 'UTC')])
            ->assertSee('>3 Mart 2025 09:12</time>', false);
        $this->blade('<x-datetime :value="$value" format="list" />', ['value' => CarbonImmutable::parse('2025-12-31 22:30:00', 'UTC')])
            ->assertSee('>1 Ocak 01:30</time>', false);

        // Yalnızca tarih (SKT gibi).
        $this->blade('<x-datetime :value="$value" format="list-date" />', ['value' => CarbonImmutable::parse('2026-12-31')])
            ->assertSee('>31 Aralık</time>', false)
            ->assertSee('title="31.12.2026"', false);
        $this->blade('<x-datetime :value="$value" format="list-date" />', ['value' => CarbonImmutable::parse('2027-05-31')])
            ->assertSee('>31 Mayıs 2027</time>', false);
    }

    public function test_layout_has_one_shared_confirm_modal(): void
    {
        // data-module="confirm-submit" formları onayı bu pencerede sorar (confirm-submit.js).
        $this->actingAs(User::factory()->create());
        $page = HTMLDocument::createFromString($this->get(route('dashboard'))->assertOk()->getContent(), LIBXML_NOERROR);

        $this->assertCount(1, $page->querySelectorAll('#confirm-modal'));
        $modal = $page->getElementById('confirm-modal');
        $this->assertTrue($modal->classList->contains('modal'));
        $this->assertSame('confirm-modal-title', $modal->getAttribute('aria-labelledby'));
        $this->assertSame('confirm-modal-message', $modal->getAttribute('aria-describedby'));
        $this->assertNotNull($modal->querySelector('#confirm-modal-title'));
        $this->assertNotNull($modal->querySelector('#confirm-modal-message'));
        $this->assertSame('Devam et', trim($modal->querySelector('button[data-confirm-accept]')->textContent));
        $this->assertSame('Vazgeç', trim($modal->querySelector('button[data-bs-dismiss="modal"].btn')->textContent));
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
