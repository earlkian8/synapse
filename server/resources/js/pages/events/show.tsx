import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArrowLeft,
    CalendarClock,
    MapPin,
    Pencil,
    Trash2,
    UserPlus,
    Users,
    Video,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { removeAttendee, updateAttendeeResponse } from '@/features/events/api';
import { EventFormSheet } from '@/features/events/components/event-form-sheet';
import {
    EventStatusBadge,
    ResponseBadge,
} from '@/features/events/components/event-status-badge';
import { InviteDialog } from '@/features/events/components/invite-dialog';
import {
    RESPONSE_LABELS,
    RESPONSE_ORDER,
    TYPE_LABELS,
    formatDateTimeRange,
} from '@/features/events/constants';
import { eventRoutes } from '@/features/events/routes';
import type {
    AttendeeResponse,
    EventAttendee,
    EventShowPageProps,
} from '@/features/events/types';
import { ConfirmDialog } from '@/features/payroll/components/confirm-dialog';
import { cn } from '@/lib/utils';

export default function EventShow() {
    const { event, invitable, can } = usePage<EventShowPageProps>().props;
    const attendees = event.attendees ?? [];
    const TypeIcon = event.type === 'meeting' ? Video : CalendarClock;

    const [inviteOpen, setInviteOpen] = useState(false);
    const [editEvent, setEditEvent] = useState(false);
    const [remove, setRemove] = useState<EventAttendee | null>(null);
    const [archiveOpen, setArchiveOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    // Response tally for the stat strip.
    const counts = useMemo(() => {
        const base: Record<AttendeeResponse, number> = {
            invited: 0,
            accepted: 0,
            declined: 0,
            tentative: 0,
        };

        for (const a of event.attendees ?? []) {
            base[a.response] += 1;
        }

        return base;
    }, [event.attendees]);

    const confirmRemove = () => {
        if (!remove) {
            return;
        }

        removeAttendee(remove.id, {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setRemove(null);
            },
        });
    };

    const archive = () =>
        router.delete(eventRoutes.destroy(event.hashid), {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setArchiveOpen(false);
            },
        });

    return (
        <>
            <Head title={`Events — ${event.title}`} />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <Link
                    href={eventRoutes.index}
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    All events
                </Link>

                {/* Event header */}
                <div className="flex flex-col gap-5 rounded-xl border border-sidebar-border/70 bg-card p-5 shadow-sm dark:border-sidebar-border">
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex min-w-0 items-start gap-3">
                            <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                <TypeIcon className="size-6" />
                            </span>
                            <div className="min-w-0">
                                <div className="flex flex-wrap items-center gap-2">
                                    <h1 className="text-xl font-semibold tracking-tight">
                                        {event.title}
                                    </h1>
                                    <EventStatusBadge status={event.status} />
                                </div>
                                <p className="text-sm text-muted-foreground">
                                    {TYPE_LABELS[event.type]} ·{' '}
                                    {formatDateTimeRange(
                                        event.starts_at,
                                        event.ends_at,
                                    )}
                                </p>
                                {event.description && (
                                    <p className="mt-2 max-w-prose text-sm text-muted-foreground">
                                        {event.description}
                                    </p>
                                )}
                            </div>
                        </div>

                        {can.manage && (
                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setEditEvent(true)}
                                >
                                    <Pencil className="size-4" />
                                    Edit
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-muted-foreground hover:text-destructive"
                                    onClick={() => setArchiveOpen(true)}
                                >
                                    <Archive className="size-4" />
                                    Archive
                                </Button>
                            </div>
                        )}
                    </div>

                    {/* Stat strip */}
                    <div className="grid grid-cols-2 gap-3 border-t border-sidebar-border/60 pt-4 sm:grid-cols-4 dark:border-sidebar-border">
                        <Stat
                            label="Location"
                            value={
                                <span className="inline-flex items-center gap-1">
                                    <MapPin className="size-3.5 text-muted-foreground" />
                                    {event.location ?? 'TBD'}
                                </span>
                            }
                        />
                        <Stat label="Invited" value={event.attendees_count} />
                        <Stat
                            label="Accepted"
                            value={counts.accepted}
                            highlight
                        />
                        <Stat
                            label="Organizer"
                            value={event.organizer?.name ?? '—'}
                        />
                    </div>
                </div>

                {/* Roster */}
                {attendees.length === 0 ? (
                    <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-14 text-center dark:border-sidebar-border">
                        <span className="flex size-10 items-center justify-center rounded-full bg-muted">
                            <Users className="size-5 text-muted-foreground" />
                        </span>
                        <p className="text-sm font-medium">
                            No one invited yet
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {can.manage
                                ? 'Use "Invite attendees" to add people to this event.'
                                : 'No employees are invited to this event.'}
                        </p>
                        {can.manage && (
                            <Button
                                size="sm"
                                className="mt-1"
                                onClick={() => setInviteOpen(true)}
                            >
                                <UserPlus className="size-4" />
                                Invite attendees
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                        <div className="flex items-center justify-between border-b border-border px-4 py-3">
                            <p className="text-sm font-semibold">
                                Attendees
                                <span className="ml-1 text-muted-foreground tabular-nums">
                                    ({attendees.length})
                                </span>
                            </p>
                            {can.manage && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setInviteOpen(true)}
                                >
                                    <UserPlus className="size-4" />
                                    Invite
                                </Button>
                            )}
                        </div>
                        <ul className="divide-y divide-border">
                            {attendees.map((attendee) => (
                                <li
                                    key={attendee.id}
                                    className="flex items-center gap-3 px-4 py-3"
                                >
                                    <PersonAvatar
                                        name={
                                            attendee.employee?.full_name ??
                                            'Unknown'
                                        }
                                        initials={
                                            attendee.employee?.initials ?? '?'
                                        }
                                        photo={attendee.employee?.photo}
                                        className="size-9"
                                    />
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {attendee.employee?.full_name ??
                                                'Unknown employee'}
                                        </p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {attendee.employee?.position ??
                                                attendee.employee
                                                    ?.employee_no ??
                                                '—'}
                                        </p>
                                    </div>
                                    {can.manage ? (
                                        <Select
                                            value={attendee.response}
                                            onValueChange={(v) =>
                                                updateAttendeeResponse(
                                                    attendee.id,
                                                    v as AttendeeResponse,
                                                )
                                            }
                                        >
                                            <SelectTrigger className="h-8 w-[7.5rem]">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {RESPONSE_ORDER.map((r) => (
                                                    <SelectItem
                                                        key={r}
                                                        value={r}
                                                    >
                                                        {RESPONSE_LABELS[r]}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    ) : (
                                        <ResponseBadge
                                            response={attendee.response}
                                        />
                                    )}
                                    {can.manage && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground hover:text-destructive"
                                            onClick={() => setRemove(attendee)}
                                            aria-label="Remove attendee"
                                        >
                                            <Trash2 className="size-4" />
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </div>

            <InviteDialog
                open={inviteOpen}
                onOpenChange={setInviteOpen}
                eventHashid={event.hashid}
                invitable={invitable}
            />

            <EventFormSheet
                event={editEvent ? event : null}
                open={editEvent}
                onOpenChange={setEditEvent}
            />

            <ConfirmDialog
                open={remove !== null}
                onOpenChange={(open) => !open && setRemove(null)}
                title="Remove attendee?"
                description={`${remove?.employee?.full_name ?? 'This employee'} will be removed from ${event.title}.`}
                confirmLabel="Remove"
                destructive
                processing={processing}
                onConfirm={confirmRemove}
            />

            <ConfirmDialog
                open={archiveOpen}
                onOpenChange={setArchiveOpen}
                title={`Archive "${event.title}"?`}
                description="It is hidden from the active list; attendees are kept. You can restore it later."
                confirmLabel="Archive"
                destructive
                processing={processing}
                onConfirm={archive}
            />
        </>
    );
}

function Stat({
    label,
    value,
    highlight = false,
}: {
    label: string;
    value: ReactNode;
    highlight?: boolean;
}) {
    return (
        <div className="flex flex-col">
            <span className="text-[11px] tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span
                className={cn(
                    'truncate text-sm font-semibold tracking-tight tabular-nums',
                    highlight && 'text-[#0a8b91] dark:text-[#0ABFBF]',
                )}
            >
                {value}
            </span>
        </div>
    );
}

EventShow.layout = {
    breadcrumbs: [{ title: 'Events & Meetings', href: '/events' }],
};
