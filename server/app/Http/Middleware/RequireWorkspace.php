<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the web app inside a workspace (ADR 0089).
 *
 * Two people reach the app without one bound by {@see SetCurrentOrganization}:
 *
 *  - somebody who **belongs to no company** — they left their last one, or
 *    registered in the phone app and have not joined one (ADR 0026). With no
 *    tenant bound, the tenant scope does nothing, so no company page may open
 *    for them: they are sent to `/workspaces` to start or join one.
 *  - somebody whose **workspace was taken away mid-session** — they left it in
 *    another tab, or its HR removed them. The session falls back to their next
 *    workspace; this tells them why and lands them on the picker, never on a
 *    page of the company they were just in, whose records no longer open.
 *
 * Their own account, signing out and the workspace screens stay reachable.
 */
class RequireWorkspace
{
    /** The request attribute {@see SetCurrentOrganization} sets: the lapsed workspace's id. */
    public const LAPSED = 'workspace_lapsed';

    /**
     * Route names that open without a workspace. A name matches when it equals an
     * entry or sits under it as a prefix ("profile." covers every profile route).
     *
     * @var list<string>
     */
    private const EXEMPT = [
        // Starting, choosing and leaving workspaces, and getting out.
        'workspaces',
        'organization.switch',
        'logout',

        // Their own account, and the flows that stand in front of it.
        'profile.',
        'security.',
        'appearance.',
        'user-password.',
        'password.',
        'two-factor.',
        'passkey.',
        'verification.',
        'tour.',

        // Public surfaces, which a signed-in person may still be looking at.
        'home',
        'files.logo',
        'careers.',
        // The kiosk (ADR 0092) tells a signed-in person to sign out first.
        'kiosk.',
        'invite.',
        'legal.',
    ];

    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            return $next($request);
        }

        $lapsed = $request->attributes->get(self::LAPSED);

        if ($lapsed !== null) {
            $name = Organization::query()->whereKey($lapsed)->value('name');

            Inertia::flash('toast', [
                'type' => 'warning',
                'message' => $name !== null
                    ? "You no longer have access to {$name}."
                    : 'That workspace is no longer available.',
            ]);

            if (! $request->expectsJson() && ! $this->isExempt($request)) {
                return $this->toPicker();
            }
        }

        if ($this->tenancy->check() || $this->isExempt($request)) {
            return $next($request);
        }

        abort_if($request->expectsJson(), 403, 'Choose a workspace first.');

        return $this->toPicker();
    }

    /**
     * A 303, so a form that was posting, patching or deleting is followed by a
     * GET (this runs before Inertia's middleware, which would otherwise see to it).
     */
    private function toPicker(): Response
    {
        return redirect()->route('workspaces', status: 303);
    }

    private function isExempt(Request $request): bool
    {
        $name = $request->route()?->getName();

        if ($name === null) {
            // Framework or dev plumbing (Inertia devtools, the health check).
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
