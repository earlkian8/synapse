<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\MobileSession;
use App\Support\Tenancy;
use App\Support\WorkspaceJoin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Joining a company from the app with its code — Google Classroom's class code,
 * for a workforce (ADR 0026).
 *
 * Two steps, deliberately: {@see preview()} confirms *which* company a code names
 * before anybody commits to it, and {@see join()} acts. Neither decides anything
 * itself; the rule about who gets in immediately and who waits for HR lives in
 * {@see WorkspaceJoin}.
 */
class WorkspaceController extends Controller
{
    public function __construct(
        private readonly MobileSession $session,
        private readonly Tenancy $tenancy,
    ) {}

    /**
     * Look up the company behind a join code without joining it — so the app can
     * ask "Join Acme Corporation?" instead of making people trust a 7-character
     * string they typed off a whiteboard.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $organization = Organization::findByJoinCode($validated['code']);

        if ($organization === null) {
            return response()->json([
                'organization' => null,
                'message' => 'That join code is not valid. Check it with your HR team.',
            ], 404);
        }

        return response()->json([
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'logo' => $organization->logo_url,
                'initials' => $organization->initials(),
            ],
            'already_member' => $request->user()->isMemberOf($organization),
        ]);
    }

    /**
     * Redeem a join code.
     *
     * Admission re-issues the session bound to the new company, so the caller can
     * act in it straight away; waiting returns the unchanged session and a `status`
     * the app renders as "we've asked, sit tight".
     */
    public function join(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32'],
        ]);

        $user = $request->user();

        try {
            $result = WorkspaceJoin::join($validated['code'], $user);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($result['status'] === WorkspaceJoin::ADMITTED) {
            return response()->json([
                'status' => WorkspaceJoin::ADMITTED,
                'message' => "You're in — welcome to {$result['organization']->name}.",
                ...$this->session->reissue($user, $result['organization']),
            ]);
        }

        // Nothing about the session changed — but the payload now carries the
        // pending request, which is what the join screen renders.
        return response()->json([
            'status' => WorkspaceJoin::PENDING,
            'message' => "Your request to join {$result['organization']->name} was sent. You'll hear back once HR reviews it.",
            'user' => $this->session->payload($user, $this->tenancy->organization()),
        ]);
    }
}
