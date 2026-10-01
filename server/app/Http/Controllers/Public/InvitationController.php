<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Support\EmployeeInvitations;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the "Accept the invitation" link in the invitation email lands (ADR 0026).
 *
 * Unauthenticated, like the careers board: the recipient may well have no account
 * anywhere yet, which is the situation the invitation exists to resolve. The page
 * does not redeem anything — redemption happens in the app, against an identity —
 * it confirms who is inviting them and shows the code to type, so the link works
 * on a laptop for somebody whose phone is where the app lives.
 */
class InvitationController extends Controller
{
    public function show(Request $request, string $token): Response
    {
        $invitation = EmployeeInvitations::findByToken($token);

        // A lapsed, revoked, or fabricated token all render the same page. Saying
        // which would tell a stranger whether a token was ever real.
        if ($invitation === null) {
            return Inertia::render('invite', ['invitation' => null]);
        }

        return Inertia::render('invite', [
            'invitation' => [
                'code' => $invitation->code,
                'email' => $invitation->email,
                'expires_human' => $invitation->expires_at?->diffForHumans(),
                'organization' => [
                    'name' => $invitation->organization?->name,
                    'logo' => $invitation->organization?->logo_url,
                    'initials' => $invitation->organization?->initials(),
                ],
                'employee' => [
                    'first_name' => $invitation->employee?->first_name,
                    'position' => $invitation->employee?->position?->title,
                    'department' => $invitation->employee?->department?->name,
                ],
            ],
        ]);
    }
}
