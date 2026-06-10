<?php

namespace App\Providers;

use App\Models\User;
use App\Support\PermissionRegistry;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The current-tenant holder must be shared for the whole request.
        $this->app->singleton(Tenancy::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->recordLastLogin();
    }

    /**
     * Wire the RBAC layer into Laravel's gate.
     *
     * Super admins bypass every check; all other abilities resolve to the
     * permission catalogue, with each permission backed by the user's roles.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        foreach (PermissionRegistry::names() as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermissionTo($permission));
        }
    }

    /**
     * Stamp the user's last login timestamp whenever they authenticate.
     */
    protected function recordLastLogin(): void
    {
        Event::listen(function (Login $event): void {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
