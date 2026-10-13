<?php

namespace App\Support\Admin;

use App\Http\Resources\Admin\AdminResource;
use App\Models\Admin;
use Illuminate\Http\Request;

/**
 * Opens a portal session once both sign-in steps are done: a bearer token that
 * can only be used as an admin (`admin` ability, `admin` guard), ending at
 * `admin.session_lifetime` minutes — or sooner, after `admin.idle_timeout`
 * minutes unused (see AdminPortalServiceProvider).
 */
class AdminSession
{
    /**
     * @return array{token: string, expires_at: string, idle_minutes: int, admin: AdminResource}
     */
    public function open(Admin $admin, Request $request): array
    {
        $expiresAt = now()->addMinutes((int) config('admin.session_lifetime'));
        $token = $admin->createToken('admin-portal', ['admin'], $expiresAt);

        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        AdminAudit::record('sign_in', 'Signed in to the admin portal', actor: $admin);

        return [
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'idle_minutes' => (int) config('admin.idle_timeout'),
            'admin' => new AdminResource($admin),
        ];
    }
}
