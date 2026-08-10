<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmployeeInvitation;
use App\Support\EmployeeInvitations;
use App\Support\MobileSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Redeeming an invitation from the app (ADR 0026) — the targeted way into a
 * company, as opposed to the join code in {@see WorkspaceController}.
 *
 * Note the asymmetry between the two endpoints here, which is deliberate and
 * explained in {@see EmployeeInvitations}: {@see index()} only ever shows
 * invitations addressed to the caller's own mailbox, while {@see accept()} takes
 * any valid code, because holding one is itself the authorisation.
 */
class InvitationController extends Controller
{
    public function __construct(private readonly MobileSession $session) {}

    /**
     * Invitations waiting for this identity's email address.
     */
    public function index(Request $request): JsonResponse
    {
        $invitations = EmployeeInvitations::for($request->user())
            ->map(fn (EmployeeInvitation $invitation): array => $this->payload($invitation))
            ->all();

        return response()->json(['data' => $invitations]);
    }

    /**
     * Look up an invitation by its code without redeeming it, so the app can name
     * the company before somebody commits.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $invitation = EmployeeInvitations::findByCode($validated['code']);

        if ($invitation === null) {
            return response()->json([
                'invitation' => null,
                'message' => 'That invitation code is not valid or has expired.',
            ], 404);
        }

        return response()->json(['invitation' => $this->payload($invitation)]);
    }

    /**
     * Redeem an invitation by code and bind this identity to the roster line it
     * names, re-issuing the session against the company they just joined.
     */
    public function accept(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $invitation = EmployeeInvitations::findByCode($validated['code']);

        if ($invitation === null) {
            return response()->json(['message' => 'That invitation code is not valid or has expired.'], 422);
        }

        $user = $request->user();

        try {
            $organization = EmployeeInvitations::accept($invitation, $user);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => "You're in — welcome to {$organization->name}.",
            ...$this->session->reissue($user, $organization),
        ]);
    }

    /**
     * Turn down an invitation addressed to this identity.
     *
     * Scoped to their own mailbox rather than to any code they hold: declining is
     * destructive to somebody else's outstanding invitation, so unlike accepting it
     * is not something possession of a forwarded code should permit.
     */
    public function decline(Request $request, string $invitation): JsonResponse
    {
        // Resolved out of the caller's own list rather than by route-model binding:
        // an invitation belongs to a tenant the caller is by definition outside of,
        // so the global scope would 404 exactly the rows that should be reachable.
        $target = EmployeeInvitations::for($request->user())
            ->first(fn (EmployeeInvitation $candidate): bool => (string) $candidate->id === $invitation);

        abort_if($target === null, 404);

        EmployeeInvitations::decline($target);

        return response()->json(['message' => 'Invitation declined.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EmployeeInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'code' => $invitation->code,
            'email' => $invitation->email,
            'expires_at' => $invitation->expires_at?->toIso8601String(),
            'expires_human' => $invitation->expires_at?->diffForHumans(),
            'organization' => [
                'id' => $invitation->organization?->id,
                'name' => $invitation->organization?->name,
                'logo' => $invitation->organization?->logo_url,
                'initials' => $invitation->organization?->initials(),
            ],
            'employee' => [
                'full_name' => $invitation->employee?->full_name,
                'employee_no' => $invitation->employee?->employee_no,
                'position' => $invitation->employee?->position?->title,
                'department' => $invitation->employee?->department?->name,
            ],
        ];
    }
}
