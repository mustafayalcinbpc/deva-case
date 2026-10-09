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
    public function create(): View
    {
        // Demo hesapları (config/demo.php) yalnızca local ortamda, inceleyen kişi giriş yapabilsin diye.
        $demoAccounts = app()->environment('local')
            ? array_map(fn (array $account) => [...$account, 'role' => UserRole::from($account['role'])], array_values(config('demo.users')))
            : [];

        return view('auth.login', [
            'demoAccounts' => $demoAccounts,
            'demoPassword' => config('demo.password'),
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
