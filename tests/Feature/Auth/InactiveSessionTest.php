<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InactiveSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_deactivated_during_a_session_is_logged_out_on_next_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->update(['is_active' => false]);

        $this->get(route('dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Hesabınız pasif. Yöneticinize başvurun.']);
        $this->assertGuest();
    }

    public function test_active_user_is_not_affected(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))->assertOk();
        $this->assertAuthenticated();
    }
}
