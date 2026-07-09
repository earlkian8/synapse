<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\EventRequest;
use App\Models\Event;
use App\Support\ActivityLogger;
use App\Support\Hashid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Manage events / meetings: create, edit, archive (soft delete), restore and
 * permanently delete. Created in-module (no Company-Setup config). Addressed by
 * hashid; restore / force-delete take it as a string. The creating user is recorded
 * as the organiser. Thin (route gate `events.manage`).
 */
class EventManagementController extends Controller
{
    public function store(EventRequest $request): RedirectResponse
    {
        $event = Event::create([
            ...$request->validated(),
            'organizer_id' => $request->user()->id,
        ]);

        ActivityLogger::log(
            event: 'created',
            description: "Scheduled {$event->type} \"{$event->title}\"",
            subject: $event,
            logName: 'events',
            subjectLabel: $event->title,
        );

        return $this->respond('Event scheduled.');
    }

    public function update(EventRequest $request, Event $event): RedirectResponse
    {
        $event->update($request->validated());

        ActivityLogger::log(
            event: 'updated',
            description: "Updated {$event->type} \"{$event->title}\"",
            subject: $event,
            logName: 'events',
            subjectLabel: $event->title,
        );

        return $this->respond('Event updated.');
    }

    /**
     * Duplicate an event: same kind, description, window and location under a
     * "(copy)" title, organised by the duplicating user. Attendees are not copied —
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

    public function destroy(Event $event): RedirectResponse
    {
        $title = $event->title;
        $event->delete();

        ActivityLogger::log(
            event: 'archived',
            description: "Archived event \"{$title}\"",
            logName: 'events',
            subjectLabel: $title,
        );

        // The event's own page no longer resolves (soft-deleted), so land on the
        // overview rather than back() into a 404.
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Event archived.']);

        return redirect()->route('events.index');
    }

    public function restore(string $event): RedirectResponse
    {
        $model = $this->findTrashed($event);
        $model->restore();

        ActivityLogger::log(
            event: 'restored',
            description: "Restored event \"{$model->title}\"",
            subject: $model,
            logName: 'events',
            subjectLabel: $model->title,
        );

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
