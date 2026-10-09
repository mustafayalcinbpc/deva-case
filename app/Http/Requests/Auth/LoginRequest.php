<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /** Aynı e-posta + IP için izin verilen başarısız deneme sayısı. */
    public const MAX_ATTEMPTS = 5;

    /** Kilidin açılmasına kadar geçen süre (saniye). */
    public const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'E-posta adresinizi girin.',
            'email.string' => 'Geçerli bir e-posta adresi girin.',
            'email.email' => 'Geçerli bir e-posta adresi girin.',
            'password.required' => 'Şifrenizi girin.',
            'password.string' => 'Şifrenizi girin.',
            'remember.boolean' => 'Beni hatırla seçeneği geçersiz.',
        ];
    }

    /**
     * Kimlik bilgilerini doğrular ve kullanıcıyı oturuma alır. Pasif kullanıcılar
     * doğru şifreyle de giriş yapamaz. Her başarısız deneme sayaca yazılır.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        // Geri çağrı yalnızca e-posta ve şifre doğruysa çalışır.
        $inactive = false;

        $authenticated = Auth::attemptWhen(
            $this->only('email', 'password'),
            function (User $user) use (&$inactive): bool {
                $inactive = ! $user->is_active;

                return ! $inactive;
            },
            $this->boolean('remember'),
        );

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => $inactive
                    ? 'Hesabınız pasif. Yöneticinize başvurun.'
                    : 'E-posta ya da şifre hatalı.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => "Çok fazla başarısız giriş denemesi. {$seconds} saniye sonra tekrar deneyin.",
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
