<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Leave has one sidebar entry and one address, `/leave`, which is HR's inbox and
 * stays guarded by `leave.view`. Somebody who may only file their own leave
 * (`leave.request`, the Staff role) is sent to My leave before that guard turns
 * them away — the dashboard's *File a leave request* and the sidebar both land.
 */
class SendSelfServiceToMyLeave
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $user->cannot('leave.view') && $user->can('leave.request')) {
            return redirect()->route('leave.me');
        }

        return $next($request);
    }
}
