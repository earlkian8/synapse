<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\RespondRequest;
use App\Http\Resources\MyInvitationResource;
use App\Models\CalendarFeed;
use App\Models\Employee;
use App\Models\Event;
use App\Queries\MyInvitations;
use App\Support\Events\EventException;
use App\Support\Events\EventWorkflow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My events (ADR 0070): the signed-in employee's own invitations on the web —
 * answering them, downloading one, and the calendar subscription link. Gated by
 * `events.respond`; an event is reachable here only by somebody invited to it
 * (anyone else gets a 404, as if it did not exist). Answers go through
 * {@see EventWorkflow::respondAsInvitee()}, as the app's and the assistant's do.
 */
class MyEventsController extends Controller
{
    /** How each answer reads back to the person who gave it. */
    public const ANSWER_LABELS = ['accepted' => 'Going', 'tentative' => 'Maybe', 'declined' => 'Not going'];

    public function index(Request $request): Response
    {
        $employee = $this->employee($request);

        return Inertia::render('events/me', [
            'invitations' => $employee
                ? MyInvitationResource::collection(MyInvitations::for($employee))->resolve($request)
                : [],
            'focus' => is_string($request->query('event')) ? $request->query('event') : null,
            'has_employee' => $employee !== null,
        ]);
    }

    public function respond(RespondRequest $request, Event $event, EventWorkflow $workflow): RedirectResponse
    {
        $employee = $this->invitee($request, $event);

        try {
            $answered = $workflow->respondAsInvitee($event, $employee, $request->validated('response'), $request->validated('scope') ?? 'this');
        } catch (EventException $e) {
            return $this->toast($e->getMessage(), 'warning');
        }

        $label = self::ANSWER_LABELS[$request->validated('response')];

        return $this->toast($answered > 1 ? "Answered: {$label} — for {$answered} dates." : "Answered: {$label}.");
    }

    /**
     * One invitation as a calendar file.
     */
    public function ics(Request $request, Event $event): HttpResponse
    {
        $this->invitee($request, $event);

        return EventIcsController::download($event);
    }

    /**
     * The subscription link, made on first ask.
     */
    public function calendar(Request $request): JsonResponse
    {
        return response()->json(CalendarFeed::issueFor($request->user())->links());
    }

    /**
     * A new subscription link; the old one stops working.
     */
    public function resetCalendar(Request $request): JsonResponse
    {
        return response()->json(CalendarFeed::issueFor($request->user())->reset()->links());
    }

    private function employee(Request $request): ?Employee
    {
        return $request->user()->employee()->first();
    }

    /**
     * The signed-in employee, when they are invited to the event; otherwise 404.
     */
    private function invitee(Request $request, Event $event): Employee
    {
        $employee = $this->employee($request);

        abort_unless($employee !== null && $event->attendees()->where('employee_id', $employee->id)->exists(), 404);

        return $employee;
    }

    private function toast(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
