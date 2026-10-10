<?php

namespace App\Support\Events;

use App\Models\Event;
use App\Models\Room;
use App\Support\OrganizationClock;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Who holds a room when (ADR 0070). Two live events never hold one room at the
 * same time: an archived event holds nothing, so archiving frees the room.
 * Back-to-back is fine — one ending at 10:00 and the next starting at 10:00 do
 * not overlap.
 */
class RoomBooking
{
    /**
     * Live events holding the room at any moment of [start, end).
     *
     * @param  list<int>  $except  Events left out — the ones being moved.
     * @return Collection<int, Event>
     */
    public function clashes(Room $room, CarbonInterface $start, CarbonInterface $end, array $except = []): Collection
    {
        return Event::query()
            ->where('room_id', $room->id)
            ->overlapping($start, $end)
            ->when($except !== [], fn ($query) => $query->whereNotIn('id', $except))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Every active room, free or not over [start, end), with the first event in
     * the way of each taken one.
     *
     * @param  list<int>  $except
     * @return list<array{room: Room, free: bool, clash: Event|null}>
     */
    public function availability(CarbonInterface $start, CarbonInterface $end, array $except = []): array
    {
        return Room::query()->active()->orderBy('name')->get()
            ->map(function (Room $room) use ($start, $end, $except): array {
                $clash = $this->clashes($room, $start, $end, $except)->first();

                return ['room' => $room, 'free' => $clash === null, 'clash' => $clash];
            })
            ->all();
    }

    /**
     * Refuse, in words, a booking the room cannot take: one with no end, a room
     * no longer booked (unless the event already holds it), or any window
     * another live event holds. The room row is locked first, so two bookings
     * made at the same moment cannot both pass.
     *
     * @param  list<array{starts_at: CarbonInterface, ends_at: CarbonInterface|null}>  $windows
     * @param  list<int>  $except  Events being moved, which do not clash with themselves.
     *
     * @throws EventException
     */
    public function assertBookable(int $roomId, array $windows, array $except = [], bool $alreadyHeld = false): Room
    {
        $room = Room::withTrashed()->lockForUpdate()->find($roomId);

        if ($room === null) {
            throw new EventException('That room no longer exists — choose another.', 'room_id');
        }

        if (! $alreadyHeld && ($room->trashed() || ! $room->is_active)) {
            throw new EventException("{$room->name} is no longer booked — choose another room.", 'room_id');
        }

        $taken = [];

        foreach ($windows as $window) {
            if ($window['ends_at'] === null) {
                throw new EventException("A room needs an end time — add one to book {$room->name}.", 'ends_at');
            }

            $clash = $this->clashes($room, $window['starts_at'], $window['ends_at'], $except)->first();

            if ($clash !== null) {
                $taken[] = [$window['starts_at'], $clash];
            }
        }

        if ($taken === []) {
            return $room;
        }

        [$when, $clash] = $taken[0];
        $day = OrganizationClock::local($when)->format('M j');

        if (count($windows) === 1) {
            throw new EventException("{$room->name} is taken then by “{$clash->title}” (".OrganizationClock::local($clash->starts_at)->format('M j, g:i A').').', 'room_id');
        }

        $others = count($taken) - 1;

        throw new EventException("{$room->name} is taken on {$day} by “{$clash->title}”".($others > 0 ? ', and on '.$others.' other '.str('date')->plural($others) : '').'.', 'room_id');
    }
}
