<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\RespondRequest;
use App\Http\Resources\MyInvitationResource;
use App\Models\Employee;
use App\Models\Event;
use App\Queries\MyInvitations;
use App\Support\Events\EventException;
use App\Support\Events\EventWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in employee's own invitations for the mobile app (ADR 0070): the
 * list, one invitation, and their answer. Self-scoped — an event they are not
 * invited to is not found — and answered through the same
 * {@see EventWorkflow::respondAsInvitee()} as the web.
 */
class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $invitations = MyInvitations::for($this->employee($request));

        return response()->json([
            'data' => MyInvitationResource::collection($invitations)->resolve($request),
            // Still to answer, for the badge on Home.
            'pending' => $invitations->filter(fn ($row): bool => $row->response === 'invited' && $row->event->status() !== 'past')->count(),
        ]);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        return response()->json(['data' => $this->invitation($request, $event)]);
    }

    public function respond(RespondRequest $request, Event $event, EventWorkflow $workflow): JsonResponse
    {
        $employee = $this->employee($request);
        $this->invitation($request, $event);

        try {
            $answered = $workflow->respondAsInvitee($event, $employee, $request->validated('response'), $request->validated('scope') ?? 'this', ' via the app');
        } catch (EventException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->invitation($request, $event), 'answered' => $answered]);
    }

    /**
     * @return array<string, mixed>
     */
    private function invitation(Request $request, Event $event): array
    {
        $row = MyInvitations::one($this->employee($request), $event->id);

        abort_if($row === null, 404);

        return (new MyInvitationResource($row))->resolve($request);
    }

    /**
     * Resolve the token user's Employee, or 403 if the account is unlinked.
     */
    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee()->first();

        abort_unless($employee !== null, 403, 'Your account is not linked to an employee record.');

        return $employee;
    }
}
