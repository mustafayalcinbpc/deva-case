<?php

namespace Tests\Feature;

use App\Enums\CleaningStatus;
use App\Models\Cleaning;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_database_gets_demo_data_covering_every_status(): void
    {
        config(['app.demo_seed' => true]);

        $this->artisan('demo:seed')
            ->expectsOutputToContain('demo verisi yükleniyor')
            ->assertSuccessful();

        $this->assertSame(5, User::count());

        foreach (CleaningStatus::cases() as $status) {
            $this->assertTrue(Cleaning::where('status', $status)->exists(), "{$status->value} durumunda kayıt yok.");
        }
    }

    public function test_database_with_data_is_left_untouched(): void
    {
        config(['app.demo_seed' => true]);
        User::factory()->create();

        $this->artisan('demo:seed')
            ->expectsOutputToContain('demo verisi yüklenmedi')
            ->assertSuccessful();

        $this->assertSame(1, User::count());
    }

    public function test_nothing_is_loaded_when_disabled(): void
    {
        config(['app.demo_seed' => false]);

        $this->artisan('demo:seed')
            ->expectsOutputToContain('kapalı')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }
}
