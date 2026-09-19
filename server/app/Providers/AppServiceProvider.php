<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Assistant\Assistant;
use App\Services\Assistant\Modules\AttendanceModule;
use App\Services\Assistant\Modules\EmployeeModule;
use App\Services\Assistant\Modules\LeaveModule;
use App\Services\Assistant\Modules\OnboardingModule;
use App\Services\Assistant\Modules\RecruitmentModule;
use App\Services\Assistant\Retrieval\Retriever;
use App\Services\Assistant\Retrieval\SubjectResolver;
use App\Support\Ai\GeminiClient;
use App\Support\Attendance\AttendanceInputs;
use App\Support\Attendance\PeriodLock;
use App\Support\Ml\MlClient;
use App\Support\PermissionRegistry;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // The attendance lock guard reads the locked periods once per request for
        // display, and fresh before every write (ADR 0039).
        $this->app->scoped(PeriodLock::class);

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
        // One module list, read twice: the assistant asks each module what it can
        // *do*, the retriever asks each what it *knows*.
        $this->app->singleton('assistant.modules', fn ($app): array => [
            $app->make(EmployeeModule::class),
            $app->make(LeaveModule::class),
            $app->make(AttendanceModule::class),
            $app->make(OnboardingModule::class),
            $app->make(RecruitmentModule::class),
        ]);

        $this->app->singleton(Retriever::class, fn ($app): Retriever => new Retriever(
            $app->make(SubjectResolver::class),
            $app->make('assistant.modules'),
        ));

        $this->app->singleton(Assistant::class, fn ($app): Assistant => new Assistant(
            $app->make(GeminiClient::class),
            $app->make('assistant.modules'),
            $app->make(Retriever::class),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureAuthorization();
        $this->configureRateLimiting();
        $this->recordLastLogin();

        // Recorded attendance days follow leave, holidays and the roster when
        // those change (ADR 0041).
        AttendanceInputs::watch();
    }

    /**
     * Rate limits for endpoints that cost something to answer.
     *
     * A chat turn is not a cheap request: it spends Gemini quota (the tier this
     * runs on is measured in requests per *minute*), it can fan out into several
     * tool calls, and each of those hits the database. Authenticated does not
     * mean unlimited — a single tab in a retry loop should not be able to burn
     * the whole organisation's daily quota. Keyed per user so one person's
     * enthusiasm cannot deny the service to their colleagues.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('assistant', fn (Request $request) => [
            Limit::perMinute(12)->by('assistant-min:'.$request->user()?->id),
            Limit::perDay(240)->by('assistant-day:'.$request->user()?->id),
        ]);

        // A device sends punches in batches; a kiosk once per person at the
        // counter. Keyed per device key, so one misbehaving scanner cannot
        // crowd out the rest of the building (ADR 0040).
        RateLimiter::for('attendance-device', fn (Request $request) => Limit::perMinute(120)
            ->by('attendance-device:'.hash('sha256', (string) ($request->bearerToken() ?? $request->header('X-Device-Key') ?? $request->ip()))));
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
