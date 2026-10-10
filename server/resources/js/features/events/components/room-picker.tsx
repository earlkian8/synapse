import { DoorOpen } from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { fetchJson, formatTime } from '../constants';
import { eventRoutes } from '../routes';
import type { RoomAvailability } from '../types';

const NONE = 'none';

type Props = {
    startsAt: string;
    endsAt: string;
    value: number | null;
    onChange: (roomId: number | null) => void;
    /** The event being edited, which never clashes with itself. */
    eventHashid?: string;
    /** The room the event holds now — kept as a choice even if retired. */
    current?: { id: number; name: string } | null;
    /** How many are invited, to warn when a room is too small. */
    invited?: number;
};

type Loaded = { key: string; rooms: RoomAvailability[] | null };

/**
 * The room picker (ADR 0070). Once the start and end are set it asks which
 * rooms are free then, and marks the taken ones with what holds them; a taken
 * room cannot be picked. Booking needs an end time, so without one it says so.
 */
export function RoomPicker({
    startsAt,
    endsAt,
    value,
    onChange,
    eventHashid,
    current,
    invited = 0,
}: Props) {
    const ready = startsAt !== '' && endsAt !== '' && endsAt > startsAt;
    const key = ready ? `${startsAt}|${endsAt}|${eventHashid ?? ''}` : '';
    const [loaded, setLoaded] = useState<Loaded>({ key: '', rooms: null });

    useEffect(() => {
        if (!ready) {
            return;
        }

        let cancelled = false;
        const params = new URLSearchParams({
            starts_at: startsAt,
            ends_at: endsAt,
            ...(eventHashid ? { event: eventHashid } : {}),
        });

        // A short pause, so typing a time does not ask on every keystroke.
        const timer = setTimeout(() => {
            fetchJson<{ rooms: RoomAvailability[] }>(
                `${eventRoutes.roomAvailability}?${params}`,
            )
                .then(
                    (data) =>
                        !cancelled && setLoaded({ key, rooms: data.rooms }),
                )
                .catch(() => !cancelled && setLoaded({ key, rooms: null }));
        }, 250);

        return () => {
            cancelled = true;
            clearTimeout(timer);
        };
    }, [ready, key, startsAt, endsAt, eventHashid]);

    const loading = ready && loaded.key !== key;
    const rooms = ready && !loading ? (loaded.rooms ?? []) : [];
    const chosen = rooms.find((room) => room.id === value);
    const keepsCurrent =
        current &&
        value === current.id &&
        !rooms.some((r) => r.id === current.id);

    if (!ready) {
        return (
            <p className="flex items-center gap-2 rounded-md border border-dashed border-border px-3 py-2 text-sm text-muted-foreground">
                <DoorOpen className="size-4 shrink-0" />
                {value !== null && current
                    ? `${current.name} — add an end time to keep it booked.`
                    : 'Set a start and an end to see which rooms are free.'}
            </p>
        );
    }

    return (
        <div className="flex flex-col gap-1.5">
            <Select
                value={value === null ? NONE : String(value)}
                onValueChange={(v) => onChange(v === NONE ? null : Number(v))}
                disabled={loading}
            >
                <SelectTrigger className="w-full" aria-label="Room">
                    <SelectValue
                        placeholder={loading ? 'Checking rooms…' : 'No room'}
                    />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>No room</SelectItem>
                    {keepsCurrent && current && (
                        <SelectItem value={String(current.id)}>
                            {current.name}
                        </SelectItem>
                    )}
                    {rooms.map((room) => (
                        <SelectItem
                            key={room.id}
                            value={String(room.id)}
                            disabled={!room.free && room.id !== value}
                        >
                            <span className="flex w-full items-center justify-between gap-3">
                                <span>
                                    {room.name}
                                    {room.capacity ? (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            · seats {room.capacity}
                                        </span>
                                    ) : null}
                                </span>
                                <span
                                    className={
                                        room.free
                                            ? 'text-xs text-emerald-600 dark:text-emerald-400'
                                            : 'text-xs text-muted-foreground'
                                    }
                                >
                                    {room.free
                                        ? 'Free'
                                        : `Taken · ${room.clash?.title ?? ''} ${formatTime(room.clash?.starts_at ?? null)}`}
                                </span>
                            </span>
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {!loading && rooms.length === 0 && !keepsCurrent && (
                <p className="text-xs text-muted-foreground">
                    No rooms yet. Add them under Events → Rooms.
                </p>
            )}
            {chosen && !chosen.free && (
                <p className="text-xs text-destructive">
                    {chosen.name} is taken then by “{chosen.clash?.title}”.
                </p>
            )}
            {chosen?.capacity && invited > chosen.capacity && (
                <p className="text-xs text-amber-600 dark:text-amber-400">
                    {invited} invited, and {chosen.name} seats {chosen.capacity}
                    .
                </p>
            )}
        </div>
    );
}
