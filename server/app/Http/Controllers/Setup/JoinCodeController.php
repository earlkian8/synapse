<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\Setup\JoinCodeSettings;
use App\Support\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * The organisation's join code — the string HR reads out so people can find the
 * company from the app (ADR 0026).
 *
 * Two controls, both of which exist because the code is a credential rather than a
 * setting: rotate it (the old one dies immediately, which is the remedy when it
 * leaks), and switch code entry off altogether for companies that would rather
 * only ever invite people by name.
 */
class JoinCodeController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy) {}

    /**
     * Issue a fresh code, invalidating the current one.
     */
    public function rotate(JoinCodeSettings $settings): RedirectResponse
    {
        $settings->rotate($this->organization());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'A new join code was generated. The previous one no longer works.']);

        return back();
    }

    /**
     * Turn code entry on or off. Outstanding invitations are unaffected — they name
     * a specific person and do not depend on the code.
     */
    public function update(Request $request, JoinCodeSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $settings->setEnabled($this->organization(), (bool) $validated['enabled']);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $validated['enabled']
                ? 'People can now join with the company code.'
                : 'Joining by code is off. Invite people individually instead.',
        ]);

        return back();
    }

    /**
     * The current tenant, which doubles as the company profile (ADR 0005).
     */
    private function organization(): Organization
    {
        return $this->tenancy->organization() ?? request()->user()->defaultOrganization() ?? abort(403);
    }
}
