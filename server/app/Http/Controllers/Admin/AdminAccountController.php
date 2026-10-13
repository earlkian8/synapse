<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AdminPasswordRequest;
use App\Http\Resources\Admin\AdminResource;
use App\Support\Admin\AdminAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in admin's own account: their password and their other sessions.
 * Both stay open while a password change is owed — the change is how it is paid.
 */
class AdminAccountController extends Controller
{
    /**
     * Choose a new password. Every other session ends, since whoever else might
     * have known the old password should not stay signed in on it.
     */
    public function password(AdminPasswordRequest $request): JsonResponse
    {
        $admin = $request->user();

        $admin->forceFill([
            'password' => $request->string('password')->toString(),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        $ended = $admin->revokeSessions($admin->currentAccessToken()->getKey());

        AdminAudit::record('password_changed', 'Changed their password', properties: ['other_sessions_ended' => $ended]);

        return response()->json([
            'message' => 'Password changed.',
            'admin' => new AdminResource($admin),
        ]);
    }

    /**
     * Sign out everywhere but here.
     */
    public function revokeOtherSessions(Request $request): JsonResponse
    {
        $admin = $request->user();
        $ended = $admin->revokeSessions($admin->currentAccessToken()->getKey());

        AdminAudit::record('sessions_revoked', $ended === 1 ? 'Ended 1 other session' : "Ended {$ended} other sessions", properties: ['ended' => $ended]);

        return response()->json([
            'message' => $ended === 0 ? 'No other sessions were open.' : ($ended === 1 ? 'Signed out of 1 other session.' : "Signed out of {$ended} other sessions."),
            'ended' => $ended,
        ]);
    }
}
