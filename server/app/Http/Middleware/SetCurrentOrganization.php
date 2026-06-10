<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Binds the authenticated user's organisation as the current tenant for the
 * request, so every tenant-owned query is automatically isolated.
 *
 * Runs after the user is resolved but before Inertia shares props or any
 * controller executes. Unauthenticated requests (login, registration) leave the
 * tenant unset.
 */
class SetCurrentOrganization
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->organization !== null) {
            $this->tenancy->set($user->organization);
        }

        return $next($request);
    }
}
