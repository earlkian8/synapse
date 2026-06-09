<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Models\User;
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

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $user->is_active ? 'User activated.' : 'User deactivated.',
        ]);

        return back();
    }
}
