<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Oturum açıkken pasife alınan kullanıcının oturumunu sonlandırır; pasif kullanıcı
 * giriş yapamadığı gibi açık kalan oturumla da işlem yapamaz.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_active === false) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Hesabınız pasif. Yöneticinize başvurun.',
            ]);
        }

        return $next($request);
    }
}
