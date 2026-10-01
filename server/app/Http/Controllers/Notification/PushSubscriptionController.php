<?php

namespace App\Http\Controllers\Notification;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /**
     * Register (or refresh) the browser push subscription for this user.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'public_key' => ['required', 'string'],
            'auth_token' => ['required', 'string'],
            'content_encoding' => ['nullable', 'string'],
        ]);

        $request->user()->updatePushSubscription(
            $data['endpoint'],
            $data['public_key'],
            $data['auth_token'],
            $data['content_encoding'] ?? null,
        );

        return back();
    }

    /**
     * Remove a browser push subscription (e.g. when the user disables push).
     */
    public function destroy(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);

        $request->user()->deletePushSubscription($data['endpoint']);

        return back();
    }
}
