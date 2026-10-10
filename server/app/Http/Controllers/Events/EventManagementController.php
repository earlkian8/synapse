<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\EventRequest;
use App\Models\Event;
use App\Support\ActivityLogger;
use App\Support\Events\EventException;
use App\Support\Events\EventWorkflow;
use App\Support\Hashid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Manage events / meetings: create (once or repeating), edit, archive (soft
 * delete), restore and permanently delete. Created in-module (no Company-Setup config). Addressed by
 * hashid; restore / force-delete take it as a string. The creating user is recorded
 * as the organiser. Thin (route gate `events.manage`): scheduling, editing and
 * archiving go through {@see EventWorkflow} — which reads the form's times as the
 * organisation's wall clock — the path the assistant takes too.
 */
class EventManagementController extends Controller
{
    public function store(EventRequest $request, EventWorkflow $workflow): RedirectResponse
    {
        $event = $this->attempt(fn (): Event => $workflow->schedule($request->eventData(), $request->user()));

        if ($event instanceof RedirectResponse) {
            return $event;
        }

        $dates = $event->series_id ? Event::query()->where('series_id', $event->series_id)->count() : 1;

        return $this->respond($dates > 1 ? "Event scheduled — {$dates} dates." : 'Event scheduled.');
    }

    public function update(EventRequest $request, Event $event, EventWorkflow $workflow): RedirectResponse
    {
        $done = $this->attempt(fn (): Event => $workflow->update($event, $request->eventData(), scope: $request->scope()));

        return $done instanceof RedirectResponse ? $done : $this->respond('Event updated.');
    }

    /**
     * Duplicate an event: same kind, description, window, location and reminder
     * under a "(copy)" title — not its room, which the original holds at that
     * time, nor its series, organised by the duplicating user. Attendees are not copied —
     * the copy starts with a clean invite list. Lands on the copy so it can be
     * rescheduled and staffed immediately.
     */
    public function duplicate(Request $request, Event $event): RedirectResponse
    {
        $copy = Event::create([
            'title' => Str::limit($event->title, 160 - strlen(' (copy)'), '').' (copy)',
            'type' => $event->type,
            'description' => $event->description,
            'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at,
            'location' => $event->location,
            'reminder_minutes' => $event->reminder_minutes,
            'organizer_id' => $request->user()->id,
        ]);

        ActivityLogger::log(
            event: 'created',
            description: "Duplicated \"{$event->title}\" as \"{$copy->title}\"",
            subject: $copy,
            logName: 'events',
            subjectLabel: $copy->title,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Event duplicated — adjust its schedule and invite attendees.']);

        return redirect()->route('events.show', $copy);
    }

    public function destroy(Request $request, Event $event, EventWorkflow $workflow): RedirectResponse
    {
        $archived = $workflow->archive($event, scope: $request->query('scope') === 'following' ? 'following' : 'this');

        // The event's own page no longer resolves (soft-deleted), so land on the
        // overview rather than back() into a 404.
        Inertia::flash('toast', ['type' => 'success', 'message' => $archived > 1 ? "{$archived} events archived." : 'Event archived.']);

        return redirect()->route('events.index');
    }

    /**
     * Bring an archived event back, unless its room was booked meanwhile.
     */
    public function restore(string $event, EventWorkflow $workflow): RedirectResponse
    {
        try {
            $workflow->restore($this->findTrashed($event));
        } catch (EventException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Event restored.');
    }

    public function forceDelete(string $event): RedirectResponse
    {
        $model = $this->findTrashed($event);

        if ($model->attendees()->exists()) {
            return $this->respond('This event has attendees and cannot be permanently deleted.', 'warning');
        }

        $title = $model->title;
        $model->forceDelete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted event \"{$title}\"",
            logName: 'events',
            subjectLabel: $title,
        );

        return $this->respond('Event permanently deleted.');
    }

    /**
     * Run a workflow call; a refusal that belongs to a form field comes back as
     * that field's error (so the form stays open with what was typed), any other
     * as a warning toast.
     *
     * @template T
     *
     * @param  \Closure(): T  $call
     * @return T|RedirectResponse
     */
    private function attempt(\Closure $call): mixed
    {
        try {
            return $call();
        } catch (EventException $e) {
            if ($e->field !== null) {
                throw ValidationException::withMessages([$e->field => $e->getMessage()]);
            }

            return $this->respond($e->getMessage(), 'warning');
        }
    }

    private function findTrashed(string $hashid): Event
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return Event::onlyTrashed()->findOrFail($id);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
