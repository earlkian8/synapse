<?php

namespace App\Http\Middleware\Admin;

use App\Models\Admin;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin accounts and the admin activity log are a super admin's alone.
 *
 * A middleware rather than the Gate: the ERP's Gate callbacks are typed to
 * {@see User}, and an admin must never be run through them.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if (! $admin instanceof Admin || ! $admin->isSuperAdmin()) {
            return response()->json(['message' => 'Only a super admin can do this.'], 403);
        }

        return $next($request);
    }
}
