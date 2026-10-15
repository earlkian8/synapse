<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\Users\OwnAccount;
use App\Support\Workspaces\Workspaces;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // Companies that would be left without an HR Manager (ADR 0089).
            'heldTogether' => Workspaces::heldTogetherBy($request->user())->pluck('name')->all(),
        ]);
    }

    /**
     * Update the user's profile information. A new email address is confirmed
     * again, with a code sent to it ({@see OwnAccount}).
     */
    public function update(ProfileUpdateRequest $request, OwnAccount $account): RedirectResponse
    {
        $account->update($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile — refused while they are the only HR Manager of
     * a company other people still work in.
     *
     * Deliberately silent: the session is invalidated on the way out, so a flash
     * toast would be written to a session nobody reads again. The landing page
     * the person arrives on is the confirmation.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        // Nobody walks away from a company nobody else could then run (ADR 0089).
        $held = Workspaces::heldTogetherBy($user);

        if ($held->isNotEmpty()) {
            throw ValidationException::withMessages([
                'account' => 'You are the only HR Manager of '.$held->pluck('name')->join(', ', ' and ').'. Make somebody else an HR Manager there first, so the people in it are not left without one.',
            ]);
        }

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
