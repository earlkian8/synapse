<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Http\Requests\Events\RoomRequest;
use App\Models\Event;
use App\Models\Room;
use App\Support\ActivityLogger;
use App\Support\Events\RoomBooking;
use App\Support\Hashid;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Events → Rooms (ADR 0070): the spaces an event can hold, each with its week of
 * bookings, and the availability check the event form asks while a time is
 * being chosen. Kept inside Events rather than Company Setup — a room is only
 * ever booked by an event. Viewing needs `events.view`; adding, editing and
 * retiring rooms, and checking availability, `events.manage`.
 */
class RoomController extends Controller
{
    /**
     * The rooms, each with its bookings in the chosen week (Monday to Sunday on
     * the office clock; this week by default).
     */
    public function index(Request $request): Response
    {
        $monday = $this->monday($request->query('week'));
        $from = OrganizationClock::at($monday->toDateString(), '00:00');
        $to = $from->addWeek();

        $rooms = Room::query()->catalogueOrder()->get();
        $bookings = Event::query()
            ->whereIn('room_id', $rooms->pluck('id'))
            ->overlapping($from, $to)
            ->orderBy('starts_at')
            ->get(['id', 'title', 'type', 'starts_at', 'ends_at', 'room_id', 'series_id'])
            ->groupBy('room_id');

        return Inertia::render('events/rooms', [
            'rooms' => $rooms->map(fn (Room $room): array => [
                ...$this->room($room),
                'bookings' => ($bookings[$room->id] ?? collect())->map(fn (Event $event): array => [
                    'hashid' => $event->hashid,
                    'title' => $event->title,
                    'type' => $event->type,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'ends_at' => $event->ends_at?->toIso8601String(),
                    'repeats' => $event->series_id !== null,
                ])->values()->all(),
            ])->all(),
            'archived' => Room::onlyTrashed()->orderBy('name')->get()->map(fn (Room $room): array => $this->room($room))->all(),
            'week' => [
                'start' => $monday->toDateString(),
                'previous' => $monday->subWeek()->toDateString(),
                'next' => $monday->addWeek()->toDateString(),
            ],
            'can' => ['manage' => $request->user()->can('events.manage')],
        ]);
    }

    /**
     * Which active rooms are free over a window — for the event form's room
     * picker. The event being edited is left out, so it never clashes with itself.
     */
    public function availability(Request $request, RoomBooking $booking): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'event' => ['nullable', 'string', 'max:64'],
        ]);

        $except = filled($data['event'] ?? null) ? array_filter([Hashid::decode($data['event'])]) : [];

        $rooms = $booking->availability(
            OrganizationClock::parse($data['starts_at']),
            OrganizationClock::parse($data['ends_at']),
            array_values($except),
        );

        return response()->json([
            'rooms' => array_map(fn (array $row): array => [
                ...$this->room($row['room']),
                'free' => $row['free'],
                'clash' => $row['clash'] ? [
                    'title' => $row['clash']->title,
                    'starts_at' => $row['clash']->starts_at?->toIso8601String(),
                    'ends_at' => $row['clash']->ends_at?->toIso8601String(),
                ] : null,
            ], $rooms),
        ]);
    }

    public function store(RoomRequest $request): RedirectResponse
    {
        $room = Room::create($request->validated());

        $this->log('created', "Added room \"{$room->name}\"", $room);

        return $this->respond('Room added.');
    }

    public function update(RoomRequest $request, Room $room): RedirectResponse
    {
        $room->update($request->validated());

        $this->log('updated', "Updated room \"{$room->name}\"", $room);

        return $this->respond('Room updated.');
    }

    /**
     * Archive a room. Its bookings stay on their events; it takes no new ones.
     */
    public function destroy(Room $room): RedirectResponse
    {
        $room->delete();

        $this->log('archived', "Archived room \"{$room->name}\"", $room);

        return $this->respond('Room archived.');
    }

    public function restore(string $room): RedirectResponse
    {
        $model = $this->findTrashed($room);
        $model->restore();

        $this->log('restored', "Restored room \"{$model->name}\"", $model);

        return $this->respond('Room restored.');
    }

    /**
     * Delete a room for good — only one no event ever held, so no booking loses
     * where it was.
     */
    public function forceDelete(string $room): RedirectResponse
    {
        $model = Room::withTrashed()->findOrFail(Hashid::decode($room) ?? abort(404));

        if (Event::withTrashed()->where('room_id', $model->id)->exists()) {
            return $this->respond("{$model->name} has bookings and cannot be deleted — archive it instead.", 'warning');
        }

        $name = $model->name;
        $model->forceDelete();

        $this->log('deleted', "Permanently deleted room \"{$name}\"", null, $name);

        return $this->respond('Room deleted.');
    }

    /**
     * @return array<string, mixed>
     */
    private function room(Room $room): array
    {
        return [
            'id' => $room->id,
            'hashid' => $room->hashid,
            'name' => $room->name,
            'location' => $room->location,
            'capacity' => $room->capacity,
            'description' => $room->description,
            'is_active' => $room->is_active,
            'is_archived' => $room->trashed(),
        ];
    }

    private function monday(mixed $week): CarbonImmutable
    {
        $day = is_string($week) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week)
            ? CarbonImmutable::parse($week, OrganizationClock::timezone())
            : OrganizationClock::now();

        return $day->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    private function findTrashed(string $hashid): Room
    {
        $id = Hashid::decode($hashid);

        abort_if($id === null, 404);

        return Room::onlyTrashed()->findOrFail($id);
    }

    private function log(string $event, string $description, ?Room $room, ?string $label = null): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $room,
            logName: 'events',
            subjectLabel: $label ?? $room?->name,
        );
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
