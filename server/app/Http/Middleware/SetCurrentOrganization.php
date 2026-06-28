<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the active organisation as the current tenant for the request, so every
 * tenant-owned query is automatically isolated.
 *
 * A user is now an identity that may belong to many organisations (ADR 0023), so
 * the active tenant is no longer a single column on `users`. It is resolved — and
 * always validated against the user's memberships, so no one can ever bind an
 * organisation they don't belong to — from:
 *
 * - the **token** for the mobile API: each personal access token is minted bound to
 *   one organisation (`personal_access_tokens.organization_id`);
 * - the **session** for the web app: the user's chosen `active_organization_id`,
 *   defaulting to (and persisting) their default membership.
 *
 * Unauthenticated requests (login, registration) leave the tenant unset.
 */
class SetCurrentOrganization
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $organization = $this->resolve($request, $user);

            if ($organization !== null) {
                $this->tenancy->set($organization);
            }
        }

        return $next($request);
    }

    /**
     * Resolve the active organisation for this request, confined to the user's
     * memberships.
     */
    private function resolve(Request $request, User $user): ?Organization
    {
        $token = $user->currentAccessToken();

        // Token-authenticated (mobile): the org is fixed on the token.
        if ($token instanceof PersonalAccessToken) {
            $organizationId = $token->organization_id;

            return $organizationId === null
                ? null
                : $this->membership($user, (int) $organizationId);
        }

        // Session-authenticated (web): the user's chosen active org, validated and
        // defaulted to their default membership (persisted so it sticks).
        if ($request->hasSession()) {
            $session = $request->session();
            $chosen = $session->get('active_organization_id');

            if ($chosen !== null && ($organization = $this->membership($user, (int) $chosen)) !== null) {
                return $organization;
            }

            $default = $user->defaultOrganization();

            if ($default !== null) {
                $session->put('active_organization_id', $default->id);
            }

            return $default;
        }

        return $user->defaultOrganization();
    }

    /**
     * The given organisation, but only if the user is a member of it.
     */
    private function membership(User $user, int $organizationId): ?Organization
    {
        return $user->memberships()->where('organizations.id', $organizationId)->first();
    }
}
