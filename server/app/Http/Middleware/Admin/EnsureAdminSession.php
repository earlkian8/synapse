<?php

namespace App\Http\Middleware\Admin;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portal's second lock, after `auth:admin` (ADR 0085).
 *
 * The guard already refuses an ERP user's token (its provider is `admins`), but
 * Sanctum would hand back a *session* user before it looks at the token. This
 * insists on what a portal request must carry: an {@see Admin}, signed in by a
 * real portal token (never a transient session token), and still active — a
 * deactivated admin's token is deleted on the spot.
 */
class EnsureAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');
        $token = $admin instanceof Admin ? $admin->currentAccessToken() : null;

        if (! $token instanceof PersonalAccessToken || ! $token->can('admin')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $admin->is_active) {
            $token->delete();

            return response()->json(['message' => 'This admin account is deactivated.'], 401);
        }

        return $next($request);
    }
}
