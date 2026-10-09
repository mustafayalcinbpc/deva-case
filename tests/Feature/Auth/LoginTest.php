<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_sees_login_form(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('action="'.route('login.store').'"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="remember"', false)
            ->assertSee('E-posta')
            ->assertSee('Şifre')
            ->assertSee('Beni hatırla')
            ->assertSee('Giriş yap');
    }

    public function test_authenticated_user_is_redirected_away_from_login(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_guest_is_redirected_to_login_from_dashboard(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_login_redirects_to_intended_url(): void
    {
        $user = User::factory()->create();

        $this->get('/')->assertRedirect(route('login'));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(url('/'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_shows_generic_error(): void
    {
        $user = User::factory()->create();

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'yanlis-sifre',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'E-posta ya da şifre hatalı.'])
            ->assertSessionHasInput('email', $user->email);

        $this->assertGuest();
    }

    public function test_unknown_email_shows_same_generic_error(): void
    {
        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'yok@demo.test',
                'password' => 'password',
            ])
            ->assertSessionHasErrors(['email' => 'E-posta ya da şifre hatalı.']);

        $this->assertGuest();
    }

    public function test_error_is_rendered_on_login_form(): void
    {
        $this->followingRedirects()
            ->from(route('login'))
            ->post(route('login.store'), [
                'email' => 'yok@demo.test',
                'password' => 'password',
            ])
            ->assertOk()
            ->assertSee('is-invalid', false)
            ->assertSee('invalid-feedback', false)
            ->assertSee('E-posta ya da şifre hatalı.')
            ->assertSee('value="yok@demo.test"', false);
    }

    public function test_missing_fields_show_turkish_validation_messages(): void
    {
        $this->from(route('login'))
            ->post(route('login.store'), ['email' => 'gecersiz', 'password' => ''])
            ->assertSessionHasErrors([
                'email' => 'Geçerli bir e-posta adresi girin.',
                'password' => 'Şifrenizi girin.',
            ]);

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in_with_correct_password(): void
    {
        $user = User::factory()->inactive()->create();

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Hesabınız pasif. Yöneticinize başvurun.']);

        $this->assertGuest();
    }

    public function test_inactive_user_with_wrong_password_gets_generic_error(): void
    {
        $user = User::factory()->inactive()->create();

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'yanlis-sifre',
            ])
            ->assertSessionHasErrors(['email' => 'E-posta ya da şifre hatalı.']);

        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_failed_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS; $i++) {
            $this->from(route('login'))
                ->post(route('login.store'), [
                    'email' => $user->email,
                    'password' => 'yanlis-sifre',
                ])
                ->assertSessionHasErrors(['email' => 'E-posta ya da şifre hatalı.']);
        }

        $this->from(route('login'))
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])
            ->assertSessionHasErrors('email');

        $message = session('errors')->first('email');
        $this->assertMatchesRegularExpression('/^Çok fazla başarısız giriş denemesi\. \d+ saniye sonra tekrar deneyin\.$/u', $message);
        $this->assertGuest();

        // Sayaç e-posta + IP bazındadır; başka bir hesap etkilenmez.
        $other = User::factory()->create();
        $this->post(route('login.store'), [
            'email' => $other->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($other);
    }

    public function test_successful_login_resets_failed_attempt_counter(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < LoginRequest::MAX_ATTEMPTS - 1; $i++) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'yanlis-sifre']);
        }

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->post(route('logout'));

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'yanlis-sifre']);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_remember_me_sets_recaller_cookie(): void
    {
        $user = User::factory()->create(['remember_token' => null]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertCookie(Auth::guard('web')->getRecallerName());

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->remember_token);
    }

    public function test_login_without_remember_me_sets_no_recaller_cookie(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertCookieMissing(Auth::guard('web')->getRecallerName());
    }

    public function test_demo_accounts_are_listed_only_in_local_environment(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Demo hesapları')
            ->assertDontSee('yonetici@demo.test');

        $this->app['env'] = 'local';

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Demo hesapları')
            ->assertSee('ahmet@demo.test')
            ->assertSee('mehmet@demo.test')
            ->assertSee('ayse@demo.test')
            ->assertSee('yonetici@demo.test')
            ->assertSee('Yönetici')
            ->assertSee('<code>password</code>', false)
            ->assertSeeInOrder(['eski@demo.test', 'pasif']);
    }
}
