<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NotificationPreferenceController extends Controller
{
    /**
     * Update the current user's per-channel notification preferences.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'boolean'],
            'push' => ['required', 'boolean'],
        ]);

        $request->user()->forceFill([
            'email_notifications' => $validated['email'],
            'push_notifications' => $validated['push'],
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Notification preferences saved.']);

        return back();
    }
}
