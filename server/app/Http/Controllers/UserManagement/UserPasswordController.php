<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\UpdateUserPasswordRequest;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class UserPasswordController extends Controller
{
    /**
     * Administratively reset a user's password.
     */
    public function update(UpdateUserPasswordRequest $request, User $user): RedirectResponse
    {
        $user->update([
            'password' => $request->validated('password'),
            'password_changed_at' => now(),
        ]);

        ActivityLogger::log(
            event: 'password_reset',
            description: "Reset password for {$user->full_name}",
            subject: $user,
            logName: 'user_management',
            subjectLabel: $user->full_name,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "Password reset for {$user->full_name}."]);

        return back();
    }
}
