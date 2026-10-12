<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
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
        Gate::define('view-projects', fn (User $user): bool => $user->role instanceof UserRole);
        Gate::define('manage-projects', fn (User $user): bool => $user->role !== UserRole::Viewer);
        Gate::define('manage-users', fn (User $user): bool => $user->role === UserRole::Admin);
    }
}
