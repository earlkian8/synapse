<?php

use App\Http\Middleware\Admin\EnsureAdminPasswordIsCurrent;
use App\Http\Middleware\Admin\EnsureAdminSession;
use App\Http\Middleware\Admin\EnsureSuperAdmin;
use App\Http\Middleware\Admin\NoStore;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireCompanySetup;
use App\Http\Middleware\RequireWorkspace;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetCurrentOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // The platform admin portal's API (ADR 0085). Its own group, not `api`:
        // that one binds an ERP tenant, and an admin stands above every tenant.
        then: function (): void {
            Route::middleware('admin-api')
                ->prefix('api/admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // Hardening headers + fingerprint stripping on every response, including
        // the /up health check and framework error pages.
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            HandleAppearance::class,
            // A deactivated account's session ends here, before any tenant is bound.
            EnsureAccountIsActive::class,
            SetCurrentOrganization::class,
            // Somebody with no workspace bound — none at all, or one just taken
            // away — is sent to the picker before any company page opens.
            RequireWorkspace::class,
            // Reads the tenant SetCurrentOrganization bound, so it must follow it: a
            // company that has never been set up sends its owner to the wizard.
            RequireCompanySetup::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Token-authenticated mobile API: bind the tenant after auth:sanctum
        // resolves the token's user, so every tenant-owned query stays isolated.
        $middleware->api(append: [
            EnsureAccountIsActive::class,
            SetCurrentOrganization::class,
        ]);

        $middleware->group('admin-api', [
            NoStore::class,
            SubstituteBindings::class,
        ]);

        $middleware->alias([
            'admin.session' => EnsureAdminSession::class,
            'admin.password-current' => EnsureAdminPasswordIsCurrent::class,
            'admin.super' => EnsureSuperAdmin::class,
        ]);

        // The tenant must be bound before route-model binding runs. Appended to
        // the groups above, both would otherwise run after SubstituteBindings, whose
        // lookups then saw no tenant: the tenant scope was a no-op and any
        // organisation's record could be reached by its id or hashid. The priority
        // list keeps them after authentication and ahead of the bindings, on web
        // and API alike.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureAccountIsActive::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetCurrentOrganization::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The mobile API, global search (ADR 0069), which the palette reads
        // with fetch(), and the kiosk (ADR 0092) with the two screens that hand
        // out its secrets: a lapsed session or a bad request must answer JSON
        // (401, 422), not a redirect to an HTML page it cannot read.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'search', 'kiosk/*', 'attendance/kiosks/pins', 'attendance/kiosks/*/pairing-code'),
        );
    })->create();
