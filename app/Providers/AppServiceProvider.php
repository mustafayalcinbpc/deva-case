<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // R-42, R-43: tanımları yalnızca yönetici yönetir, raporları yalnızca yönetici görür.
        Gate::define('manage-definitions', fn (User $user): bool => $user->isManager());
        Gate::define('view-reports', fn (User $user): bool => $user->isManager());
    }
}
