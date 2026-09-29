<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Users\UserAccountException;
use App\Support\Users\UserAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserStatusController extends Controller
{
    /**
     * Toggle (or explicitly set) a user's active state.
     */
    public function update(Request $request, User $user, UserAccounts $accounts): RedirectResponse
    {
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        try {
            $accounts->setActive($user, $request->boolean('is_active'), $request->user());
        } catch (UserAccountException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $user->is_active ? 'User activated.' : 'User deactivated.',
        ]);

        return back();
    }
}
