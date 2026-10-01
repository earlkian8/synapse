<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of an account that has been deactivated (ADR 0057).
 *
 * Signing in already refuses an inactive account — the web through
 * `Fortify::authenticateUsing()` and the phone through the API login — but a
 * session opened *before* the account was deactivated kept working, and so did
 * a passkey sign-in, so "Deactivate" did not stop anybody who was already in.
 * This runs on every web and API request: the web session is signed out and
 * sent to the login page, and the phone's token is revoked.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->is_active) {
            return $next($request);
        }

        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();

            return response()->json(['message' => 'This account is inactive.'], 401);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'This account is inactive.'], 401)
            : redirect()->route('login')->with('status', 'This account has been deactivated. Ask your administrator if you need access again.');
    }
}
