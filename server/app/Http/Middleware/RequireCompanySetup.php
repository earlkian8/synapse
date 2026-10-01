<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Setup\SetupWizardController;
use App\Support\Setup\CompanySetup;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends a company that has never been set up to the setup wizard, once, before
 * it reaches the rest of the app.
 *
 * Registration provisions an empty tenant (ADR 0005), so the owner's first
 * sign-in would otherwise land on a dashboard of zeroes. This is the redirect
 * that makes {@see SetupWizardController} the first thing they see; finishing the
 * wizard — or saying they will configure things themselves — clears it forever
 * (`organizations.setup_completed_at`).
 *
 * It is deliberately narrow. It acts only on:
 *
 *  - a **page navigation** (GET/HEAD, not JSON) — a form post, an API call and a
 *    CSV download are never bounced mid-flight;
 *  - by somebody who **can actually do setup** — a Staff member joining a
 *    half-configured company is not trapped in a wizard they may not use;
 *  - **outside the exempt set** — the wizard's own routes, the ways out of the
 *    app, and the person's own account settings, which belong to them rather
 *    than to the company.
 *
 * Companies that predate the wizard were back-filled as complete, so nobody is
 * sent through setup for a company they have been running for months.
 */
class RequireCompanySetup
{
    /**
     * Route names that stay reachable while setup is outstanding. A name matches
     * when it equals an entry or sits under it as a prefix ("settings." covers
     * every settings route).
     *
     * @var list<string>
     */
    private const EXEMPT = [
        // The wizard itself, and the dashboard hand-off it ends on.
        'setup.wizard.',

        // Getting out, and choosing a different company to work in — an owner
        // with an unconfigured company must still be able to leave it.
        'logout',
        'workspaces',
        'organization.switch',

        // Their own account: profile, security, appearance, and the flows that
        // stand in front of them.
        'profile.',
        'security.',
        'appearance.',
        'user-password.',
        'password.',
        'two-factor.',
        'passkey.',
        'verification.',

        // Public surfaces, which a signed-in owner may still be looking at.
        'home',
        'careers.',
        'invite.',
        'legal.',
    ];

    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organization = $this->tenancy->organization();

        if ($user === null || $organization === null || $organization->hasFinishedSetup()) {
            return $next($request);
        }

        if (! in_array($request->method(), ['GET', 'HEAD'], true) || $request->expectsJson()) {
            return $next($request);
        }

        // The wizard's steps are gated one permission each, but reaching it at
        // all is the company-profile ability — the one the owner is given.
        if (! $user->can(CompanySetup::ABILITIES[CompanySetup::COMPANY])) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        return redirect()->route('setup.wizard.show');
    }

    /**
     * Whether this request's route is one setup is allowed to interrupt.
     */
    private function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName();

        if ($name === null) {
            // An unnamed route is framework or dev plumbing (Inertia devtools,
            // the health check); leave it alone.
            return true;
        }

        foreach (self::EXEMPT as $exempt) {
            if ($name === $exempt || str_starts_with($name, $exempt)) {
                return true;
            }
        }

        return false;
    }
}
