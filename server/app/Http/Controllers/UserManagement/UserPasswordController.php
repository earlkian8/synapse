<?php

namespace App\Http\Controllers\UserManagement;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserManagement\UpdateUserPasswordRequest;
use App\Models\User;
use App\Support\Users\UserAccountException;
use App\Support\Users\UserAccounts;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class UserPasswordController extends Controller
{
    /**
     * Administratively reset a user's password.
     */
    public function update(UpdateUserPasswordRequest $request, User $user, UserAccounts $accounts): RedirectResponse
    {
        try {
            $accounts->resetPassword($user, $request->validated('password'), $request->user());
        } catch (UserAccountException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "Password reset for {$user->full_name}."]);

        return back();
    }
}
