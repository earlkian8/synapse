<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\Modules\AttendanceModule;
use App\Services\Assistant\Modules\EmployeeModule;
use App\Services\Assistant\Modules\LeaveModule;
use App\Services\Assistant\Modules\OnboardingModule;
use App\Services\Assistant\Modules\RecruitmentModule;
use App\Support\Ai\GeminiClient;
use App\Support\Ml\MlClient;
use App\Support\PermissionRegistry;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
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

        // The Gemini client backing the agentic assistant.
        $this->app->singleton(GeminiClient::class, fn (): GeminiClient => new GeminiClient(
            apiKey: config('services.gemini.key'),
            model: config('services.gemini.model'),
        ));

        // The ML inference client backing the Predictive Analytics surfaces.
        $this->app->singleton(MlClient::class, fn (): MlClient => new MlClient(
            baseUrl: config('services.ml.url'),
            timeout: config('services.ml.timeout'),
        ));

        // The agentic assistant and the HR modules it can act on. Each module is
        // permission-gated per user at runtime; register them all here.
        $this->app->singleton(Assistant::class, fn ($app): Assistant => new Assistant(
            $app->make(GeminiClient::class),
            [
                $app->make(EmployeeModule::class),
                $app->make(LeaveModule::class),
                $app->make(AttendanceModule::class),
                $app->make(OnboardingModule::class),
                $app->make(RecruitmentModule::class),
            ],
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureEmailVerification();
        $this->recordLastLogin();
    }

    /**
     * Brand the email-verification ("confirm your address") message.
     *
     * We customise the standard {@see VerifyEmail} notification in place rather
     * than swapping in a subclass, so the framework's verification flow — the
     * signed link, the `Registered` listener, Fortify's resend endpoint — keeps
     * working untouched. Delivery rides the app's default mailer (Brevo SMTP).
     */
    protected function configureEmailVerification(): void
    {
        VerifyEmail::toMailUsing(function (object $notifiable, string $url): MailMessage {
            $app = config('app.name');

            return (new MailMessage)
                ->subject("Verify your email address — {$app}")
                ->greeting("Welcome to {$app}!")
                ->line('Please confirm your email address to activate your account and start using your workspace.')
                ->action('Verify email address', $url)
                ->line('This link expires in '.config('auth.verification.expire', 60).' minutes. If you didn’t expect this email, you can safely ignore it.')
                ->salutation("— The {$app} Team");
        });
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

        // In production, force every generated URL (redirects, assets, signed
        // links, the XSRF/session cookies' secure scheme) onto HTTPS so nothing
        // — credentials included — is ever emitted over plaintext http://.
        URL::forceHttps(app()->isProduction());

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
