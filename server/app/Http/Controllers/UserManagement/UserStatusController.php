<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserStatusController extends Controller
{
    /**
     * Toggle (or explicitly set) a user's active state.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        if ($user->is($request->user()) && ! $request->boolean('is_active')) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'You cannot deactivate your own account.']);

            return back();
        }

        $user->update(['is_active' => $request->boolean('is_active')]);

        ActivityLogger::log(
            event: $user->is_active ? 'activated' : 'deactivated',
            description: ($user->is_active ? 'Activated user ' : 'Deactivated user ').$user->full_name,
            subject: $user,
            logName: 'user_management',
            subjectLabel: $user->full_name,
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $user->is_active ? 'User activated.' : 'User deactivated.',
        ]);

        return back();
    }
}
