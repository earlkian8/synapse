<?php

namespace App\Http\Middleware\Admin;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds an admin whose password was issued by somebody else at the password
 * screen: until they choose their own, only their session and the password
 * change itself answer. The portal reads `reason` to route there.
 */
class EnsureAdminPasswordIsCurrent
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin instanceof Admin && $admin->must_change_password) {
            return response()->json([
                'message' => 'Choose a new password before you continue.',
                'reason' => 'password_change_required',
            ], 403);
        }

        return $next($request);
    }
}
