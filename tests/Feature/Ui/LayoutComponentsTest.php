<?php

namespace Tests\Feature\Ui;

use App\Enums\CleaningStatus;
use App\Enums\StepStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
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

    public function test_sidebar_shows_items_by_role_and_hides_empty_headers(): void
    {
        config(['menu' => [
            ['label' => 'Gösterge Paneli', 'icon' => 'bi-speedometer2', 'route' => 'dashboard'],
            ['header' => 'Yönetim', 'roles' => ['manager']],
            ['label' => 'Tanımlar', 'icon' => 'bi-gear', 'route' => 'dashboard', 'roles' => ['manager']],
        ]]);

        $this->actingAs(User::factory()->create());
        $this->blade('<x-sidebar-menu />')
            ->assertSee('Gösterge Paneli')
            ->assertDontSee('Yönetim')
            ->assertDontSee('Tanımlar');

        $this->actingAs(User::factory()->manager()->create());
        $this->blade('<x-sidebar-menu />')
            ->assertSee('Yönetim')
            ->assertSee('Tanımlar');
    }

    public function test_items_whose_route_is_not_defined_are_hidden(): void
    {
        config(['menu' => [
            ['label' => 'Gösterge Paneli', 'icon' => 'bi-speedometer2', 'route' => 'dashboard'],
            ['header' => 'Raporlar'],
            ['label' => 'Henüz yok', 'icon' => 'bi-gear', 'route' => 'reports.not-yet-defined'],
        ]]);

        $this->actingAs(User::factory()->manager()->create());

        $this->blade('<x-sidebar-menu />')
            ->assertSee('Gösterge Paneli')
            ->assertDontSee('Henüz yok')
            ->assertDontSee('Raporlar');
    }

    public function test_header_without_visible_items_is_hidden(): void
    {
        config(['menu' => [
            ['header' => 'Boş başlık'],
            ['label' => 'Yalnızca yönetici', 'icon' => 'bi-gear', 'route' => 'dashboard', 'roles' => ['manager']],
        ]]);

        $this->actingAs(User::factory()->create());

        $this->blade('<x-sidebar-menu />')->assertDontSee('Boş başlık');
    }
}
