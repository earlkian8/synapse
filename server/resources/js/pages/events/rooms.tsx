import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ChevronLeft,
    ChevronRight,
    DoorOpen,
    MoreHorizontal,
    Pencil,
    Plus,
    Repeat,
    RotateCcw,
    Trash2,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DataTable,
    EmptyTableRow,
    PageBody,
    PageHeader,
    TableCard,
} from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { EventsNav } from '@/features/events/components/events-nav';
import { RoomFormSheet } from '@/features/events/components/room-form-sheet';
import { formatTime } from '@/features/events/constants';
import { eventRoutes } from '@/features/events/routes';
import type { RoomItem, RoomsPageProps } from '@/features/events/types';
import { cn } from '@/lib/utils';

/** "2026-10-12" as a local date, midnight. */
function day(iso: string): Date {
    return new Date(`${iso}T00:00:00`);
}

function sameDay(a: Date, b: Date): boolean {
    return a.toDateString() === b.toDateString();
}

/**
 * Events → Rooms (ADR 0070): each room's week at a glance — what holds it on
 * which day — and the rooms themselves, added, edited, retired or archived here.
 */
export default function Rooms() {
    const { rooms, archived, week, can } = usePage<RoomsPageProps>().props;

    const [form, setForm] = useState<{ open: boolean; room: RoomItem | null }>({
        open: false,
        room: null,
    });
    const [confirm, setConfirm] = useState<{
        title: string;
        description: string;
        label: string;
        run: () => void;
    } | null>(null);
    const [processing, setProcessing] = useState(false);
    const [showArchived, setShowArchived] = useState(false);

    const days = useMemo(() => {
        const monday = day(week.start);

        return Array.from({ length: 7 }, (_, i) => {
            const date = new Date(monday);
            date.setDate(monday.getDate() + i);

            return date;
        });
    }, [week.start]);

    const today = new Date();
    const range = `${days[0].toLocaleDateString(undefined, { month: 'short', day: 'numeric' })} – ${days[6].toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })}`;

    const visits = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirm(null);
        },
    };

    const archive = (room: RoomItem) =>
        setConfirm({
            title: `Archive ${room.name}?`,
            description:
                'It takes no new bookings and leaves this list. Events that hold it keep it. You can restore it later.',
            label: 'Archive',
            run: () =>
                router.delete(eventRoutes.roomDestroy(room.hashid), visits),
        });

    const forceDelete = (room: RoomItem) =>
        setConfirm({
            title: `Delete ${room.name} for good?`,
            description:
                'Only a room no event ever held can be deleted. This cannot be undone.',
            label: 'Delete',
            run: () =>
                router.delete(eventRoutes.roomForceDelete(room.hashid), visits),
        });

    return (
        <>
            <Head title="Rooms" />

            <PageBody>
                <PageHeader
                    title="Rooms"
                    description="Which event holds each room, week by week. Book a room by scheduling an event in it."
                    actions={<EventsNav current="rooms" />}
                />

                <TableCard
                    title={range}
                    actions={
                        <div className="flex items-center gap-1">
                            {can.manage && (
                                <>
                                    <Button
                                        size="sm"
                                        onClick={() =>
                                            setForm({ open: true, room: null })
                                        }
                                    >
                                        <Plus className="size-4" />
                                        New room
                                    </Button>
                                    <span
                                        aria-hidden
                                        className="mx-1.5 h-5 w-px bg-border"
                                    />
                                </>
                            )}
                            <Button
                                variant="outline"
                                size="icon"
                                className="size-8"
                                asChild
                            >
                                <Link
                                    href={eventRoutes.roomsWeek(week.previous)}
                                    preserveScroll
                                    aria-label="Previous week"
                                >
                                    <ChevronLeft className="size-4" />
                                </Link>
                            </Button>
                            <Button variant="outline" size="sm" asChild>
                                <Link href={eventRoutes.rooms} preserveScroll>
                                    This week
                                </Link>
                            </Button>
                            <Button
                                variant="outline"
                                size="icon"
                                className="size-8"
                                asChild
                            >
                                <Link
                                    href={eventRoutes.roomsWeek(week.next)}
                                    preserveScroll
                                    aria-label="Next week"
                                >
                                    <ChevronRight className="size-4" />
                                </Link>
                            </Button>
                        </div>
                    }
                >
                    <DataTable>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="min-w-40">Room</TableHead>
                                {days.map((date) => (
                                    <TableHead
                                        key={date.toISOString()}
                                        className={cn(
                                            'min-w-28 text-center',
                                            sameDay(date, today) &&
                                                'text-foreground',
                                        )}
                                    >
                                        <span className="block text-[11px] font-normal text-muted-foreground">
                                            {date.toLocaleDateString(
                                                undefined,
                                                { weekday: 'short' },
                                            )}
                                        </span>
                                        <span
                                            className={cn(
                                                'inline-flex size-6 items-center justify-center rounded-full tabular-nums',
                                                sameDay(date, today) &&
                                                    'bg-[#0ABFBF] text-[#0f2044]',
                                            )}
                                        >
                                            {date.getDate()}
                                        </span>
                                    </TableHead>
                                ))}
                                {can.manage && <TableHead className="w-10" />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rooms.length === 0 && (
                                <EmptyTableRow
                                    colSpan={can.manage ? 9 : 8}
                                    icon={DoorOpen}
                                    title="No rooms yet"
                                    description={
                                        can.manage
                                            ? 'Add the rooms your events use, and the event form shows which are free.'
                                            : 'HR has not added any rooms.'
                                    }
                                    action={
                                        can.manage && (
                                            <Button
                                                size="sm"
                                                onClick={() =>
                                                    setForm({
                                                        open: true,
                                                        room: null,
                                                    })
                                                }
                                            >
                                                <Plus className="size-4" />
                                                New room
                                            </Button>
                                        )
                                    }
                                />
                            )}
                            {rooms.map((room) => (
                                <TableRow key={room.id} className="align-top">
                                    <TableCell>
                                        <div className="flex flex-col gap-0.5">
                                            <span className="flex items-center gap-2 font-medium">
                                                {room.name}
                                                {!room.is_active && (
                                                    <Badge
                                                        variant="outline"
                                                        className="text-[10px]"
                                                    >
                                                        Not booking
                                                    </Badge>
                                                )}
                                            </span>
                                            <span className="flex items-center gap-2 text-xs text-muted-foreground">
                                                {room.location}
                                                {room.capacity && (
                                                    <span className="inline-flex items-center gap-0.5">
                                                        <Users className="size-3" />
                                                        {room.capacity}
                                                    </span>
                                                )}
                                            </span>
                                        </div>
                                    </TableCell>
                                    {days.map((date) => {
                                        const bookings = room.bookings.filter(
                                            (b) =>
                                                b.starts_at &&
                                                sameDay(
                                                    new Date(b.starts_at),
                                                    date,
                                                ),
                                        );

                                        return (
                                            <TableCell
                                                key={date.toISOString()}
                                                className="px-1.5"
                                            >
                                                <div className="flex flex-col gap-1">
                                                    {bookings.map((booking) => (
                                                        <Link
                                                            key={booking.hashid}
                                                            href={eventRoutes.show(
                                                                booking.hashid,
                                                            )}
                                                            className={cn(
                                                                'block rounded-md border-l-2 px-2 py-1 text-left text-xs leading-tight transition-colors hover:bg-muted',
                                                                booking.type ===
                                                                    'meeting'
                                                                    ? 'border-[#0ABFBF] bg-[#0ABFBF]/10'
                                                                    : 'border-sky-500 bg-sky-500/10',
                                                            )}
                                                        >
                                                            <span className="flex items-center gap-1 font-medium tabular-nums">
                                                                {formatTime(
                                                                    booking.starts_at,
                                                                )}
                                                                {booking.repeats && (
                                                                    <Repeat
                                                                        className="size-3 text-muted-foreground"
                                                                        aria-label="Repeats"
                                                                    />
                                                                )}
                                                            </span>
                                                            <span className="line-clamp-2 text-muted-foreground">
                                                                {booking.title}
                                                            </span>
                                                        </Link>
                                                    ))}
                                                </div>
                                            </TableCell>
                                        );
                                    })}
                                    {can.manage && (
                                        <TableCell className="text-right">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        aria-label={`Actions for ${room.name}`}
                                                    >
                                                        <MoreHorizontal className="size-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end">
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            setForm({
                                                                open: true,
                                                                room,
                                                            })
                                                        }
                                                    >
                                                        <Pencil className="size-4" />
                                                        Edit
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            archive(room)
                                                        }
                                                    >
                                                        <Archive className="size-4" />
                                                        Archive
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </DataTable>
                </TableCard>

                {can.manage && archived.length > 0 && (
                    <div className="flex flex-col gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            className="self-start text-muted-foreground"
                            onClick={() => setShowArchived((v) => !v)}
                        >
                            <Archive className="size-4" />
                            {showArchived ? 'Hide' : 'Show'} archived rooms (
                            {archived.length})
                        </Button>
                        {showArchived && (
                            <ul className="divide-y divide-border rounded-xl border border-sidebar-border/70 bg-card">
                                {archived.map((room) => (
                                    <li
                                        key={room.id}
                                        className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm"
                                    >
                                        <span>
                                            {room.name}
                                            {room.location && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {room.location}
                                                </span>
                                            )}
                                        </span>
                                        <span className="flex gap-1">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.patch(
                                                        eventRoutes.roomRestore(
                                                            room.hashid,
                                                        ),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <RotateCcw className="size-4" />
                                                Restore
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                className="text-muted-foreground hover:text-destructive"
                                                onClick={() =>
                                                    forceDelete(room)
                                                }
                                            >
                                                <Trash2 className="size-4" />
                                                Delete
                                            </Button>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </PageBody>

            <RoomFormSheet
                room={form.room}
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
            />

            {confirm && (
                <ConfirmDialog
                    open
                    onOpenChange={(open) => !open && setConfirm(null)}
                    title={confirm.title}
                    description={confirm.description}
                    confirmLabel={confirm.label}
                    destructive
                    processing={processing}
                    onConfirm={confirm.run}
                />
            )}
        </>
    );
}

Rooms.layout = {
    breadcrumbs: [
        { title: 'Events & Meetings', href: '/events' },
        { title: 'Rooms', href: '/events/rooms' },
    ],
};
