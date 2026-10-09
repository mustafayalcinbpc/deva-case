<?php

namespace App\Http\Controllers\Auth;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    /**
     * DemoSeeder'ın oluşturduğu hesaplar (şifre: password). Giriş sayfasında
     * yalnızca local ortamda, inceleyen kişi giriş yapabilsin diye gösterilir.
     *
     * @var list<array{email: string, role: UserRole, active: bool}>
     */
    private const DEMO_ACCOUNTS = [
        ['email' => 'ahmet@demo.test', 'role' => UserRole::Operator, 'active' => true],
        ['email' => 'mehmet@demo.test', 'role' => UserRole::Operator, 'active' => true],
        ['email' => 'ayse@demo.test', 'role' => UserRole::Operator, 'active' => true],
        ['email' => 'yonetici@demo.test', 'role' => UserRole::Manager, 'active' => true],
        ['email' => 'eski@demo.test', 'role' => UserRole::Operator, 'active' => false],
    ];

    public function create(): View
    {
        return view('auth.login', [
            'demoAccounts' => app()->environment('local') ? self::DEMO_ACCOUNTS : [],
        ]);
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
