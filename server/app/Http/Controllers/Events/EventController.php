<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Models\Employee;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Events & Meetings — the live view of the organisation's scheduled events and
 * meetings: an overview grouped by their derived status, and a single event with
 * its invitee roster. Events are created in this module (no Company-Setup config)
 * and addressed by hashid.
 */
class EventController extends Controller
{
    /**
     * The events overview: KPIs + a card per event.
     */
    public function index(Request $request): Response
    {
        $events = $this->withCounts(Event::query())->chronological()->get();
        $archived = $this->withCounts(Event::query()->onlyTrashed())->recentFirst()->get();

        return Inertia::render('events/index', [
            'events' => EventResource::collection($events)->resolve($request),
            'archived' => EventResource::collection($archived)->resolve($request),
            'stats' => $this->stats($events),
            'can' => $this->permissions($request),
        ]);
    }

    /**
     * A single event with its invitee roster and the employees still available to
     * invite.
     */
    public function show(Request $request, Event $event): Response
    {
        $event->loadCount([
            'attendees',
            'attendees as attending_count' => fn (Builder $query) => $query->attending(),
        ])->load([
            'organizer:id,first_name,last_name',
            'attendees' => fn ($query) => $query->orderBy('id'),
            'attendees.employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
            'attendees.employee.department:id,name',
            'attendees.employee.position:id,title',
        ]);

        return Inertia::render('events/show', [
            'event' => (new EventResource($event))->resolve($request),
            'invitable' => $this->invitableEmployees($event),
            'can' => $this->permissions($request),
        ]);
    }

    /**
     * Attach the attendee aggregates the cards read.
     *
     * @param  Builder<Event>  $query
     * @return Builder<Event>
     */
    private function withCounts(Builder $query): Builder
    {
        return $query
            ->with('organizer:id,first_name,last_name')
            ->withCount('attendees')
            ->withCount(['attendees as attending_count' => fn (Builder $q) => $q->attending()]);
    }

    /**
     * Active employees not yet invited to this event, for the invite picker.
     *
     * @return list<array{id: int, full_name: string, employee_no: string}>
     */
    private function invitableEmployees(Event $event): array
    {
        $invited = $event->attendees()->pluck('employee_id');

        return Employee::query()
            ->where('employment_status', 'active')
            ->whereNotIn('id', $invited)
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'employee_no' => $employee->employee_no,
            ])
            ->all();
    }

    /**
     * Headline metrics, derived from the event collection.
     *
     * @param  Collection<int, Event>  $events
     * @return array<string, int>
     */
    private function stats($events): array
    {
        return [
            'total' => $events->count(),
            'upcoming' => $events->filter(fn (Event $e): bool => $e->status() === 'upcoming')->count(),
            'meetings' => $events->where('type', 'meeting')->count(),
            'invitations' => (int) $events->sum('attendees_count'),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        return ['manage' => $request->user()->can('events.manage')];
    }
}
