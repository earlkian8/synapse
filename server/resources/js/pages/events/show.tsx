import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    BellRing,
    CalendarClock,
    CalendarPlus,
    CircleHelp,
    Copy,
    Download,
    MailQuestion,
    Pencil,
    ThumbsUp,
    Trash2,
    UserPlus,
    Users,
    Video,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DataTable,
    EmptyTableRow,
    HeaderIcon,
    PageBody,
    PageHeader,
    SearchInput,
    StatTiles,
    TableCard,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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

export default function EventShow() {
    const { event, invitable, can } = usePage<EventShowPageProps>().props;
    const attendees = useMemo(() => event.attendees ?? [], [event.attendees]);
    const TypeIcon = event.type === 'meeting' ? Video : CalendarClock;

    const [inviteOpen, setInviteOpen] = useState(false);
    const [editEvent, setEditEvent] = useState(false);
    const [remove, setRemove] = useState<EventAttendee | null>(null);
    const [archiveOpen, setArchiveOpen] = useState(false);
    const [remindOpen, setRemindOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    // Response tally for the stat strip.
    const counts = useMemo(() => {
        const base: Record<AttendeeResponse, number> = {
            invited: 0,
            accepted: 0,
            declined: 0,
            tentative: 0,
        };

        for (const a of attendees) {
            base[a.response] += 1;
        }

        return base;
    }, [attendees]);

    // The roster can be long: searched and paged like every other table.
    const [search, setSearch] = useState('');
    const matching = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return needle === ''
            ? attendees
            : attendees.filter((a) =>
                  [a.employee?.full_name, a.employee?.employee_no]
                      .filter(Boolean)
                      .some((field) => field!.toLowerCase().includes(needle)),
              );
    }, [attendees, search]);
    const roster = useClientPagination(matching, search);

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

    const duplicate = () =>
        router.post(
            eventRoutes.duplicate(event.hashid),
            {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    const remindPending = () =>
        router.post(
            eventRoutes.remind(event.hashid),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setRemindOpen(false);
                },
            },
        );

    return (
        <>
            <Head title={`Events — ${event.title}`} />

            <PageBody>
                <PageHeader
                    back={{
                        href: eventRoutes.index,
                        label: 'Back to all events',
                    }}
                    leading={
                        <HeaderIcon>
                            <TypeIcon />
                        </HeaderIcon>
                    }
                    title={event.title}
                    badges={<EventStatusBadge status={event.status} />}
                    description={
                        <>
                            {[
                                TYPE_LABELS[event.type],
                                formatDateTimeRange(
                                    event.starts_at,
                                    event.ends_at,
                                ),
                                event.location ?? 'Location TBD',
                                event.organizer
                                    ? `Organised by ${event.organizer.name}`
                                    : null,
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                            {event.description && (
                                <span className="mt-1 line-clamp-2 block max-w-3xl">
                                    {event.description}
                                </span>
                            )}
                        </>
                    }
                    actions={
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <a href={eventRoutes.ics(event.hashid)}>
                                    <CalendarPlus className="size-4" />
                                    Add to calendar
                                </a>
                            </Button>
                            {can.manage && (
                                <>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setEditEvent(true)}
                                    >
                                        <Pencil className="size-4" />
                                        Edit
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        disabled={processing}
                                        onClick={duplicate}
                                    >
                                        <Copy className="size-4" />
                                        Duplicate
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
                                </>
                            )}
                        </>
                    }
                />

                <StatTiles
                    tiles={[
                        {
                            key: 'invited',
                            label: 'Invited',
                            value: event.attendees_count.toLocaleString(),
                            icon: Users,
                            accent: 'teal',
                        },
                        {
                            key: 'accepted',
                            label: 'Accepted',
                            value: counts.accepted.toLocaleString(),
                            icon: ThumbsUp,
                            accent: 'emerald',
                        },
                        {
                            key: 'tentative',
                            label: 'Tentative',
                            value: counts.tentative.toLocaleString(),
                            icon: CircleHelp,
                            accent: 'amber',
                        },
                        {
                            key: 'pending',
                            label: 'Not replied',
                            value: counts.invited.toLocaleString(),
                            icon: MailQuestion,
                            accent: 'slate',
                            hint:
                                counts.declined > 0
                                    ? `· ${counts.declined} declined`
                                    : undefined,
                        },
                    ]}
                />

                <div className="flex flex-col gap-3">
                    <TableCard
                        title="Attendees"
                        count={attendees.length}
                        actions={
                            <>
                                {attendees.length > 0 && (
                                    <SearchInput
                                        value={search}
                                        onSearch={setSearch}
                                        delay={0}
                                        placeholder="Search attendees…"
                                        label="Search attendees"
                                        className="sm:w-56"
                                    />
                                )}
                                {attendees.length > 0 && (
                                    <Button variant="outline" size="sm" asChild>
                                        <a
                                            href={eventRoutes.rosterExport(
                                                event.hashid,
                                            )}
                                        >
                                            <Download className="size-4" />
                                            Export
                                        </a>
                                    </Button>
                                )}
                                {can.manage &&
                                    counts.invited > 0 &&
                                    event.status !== 'past' && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => setRemindOpen(true)}
                                        >
                                            <BellRing className="size-4" />
                                            Remind pending
                                            <span className="tabular-nums">
                                                ({counts.invited})
                                            </span>
                                        </Button>
                                    )}
                                {can.manage && (
                                    <Button
                                        size="sm"
                                        onClick={() => setInviteOpen(true)}
                                    >
                                        <UserPlus className="size-4" />
                                        Invite
                                    </Button>
                                )}
                            </>
                        }
                    >
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Position</TableHead>
                                    <TableHead>Response</TableHead>
                                    {can.manage && (
                                        <TableHead className="w-10" />
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {attendees.length === 0 && (
                                    <EmptyTableRow
                                        colSpan={can.manage ? 4 : 3}
                                        icon={Users}
                                        title="No one invited yet"
                                        description={
                                            can.manage
                                                ? 'Invite people to this event and their replies land here.'
                                                : 'No employees are invited to this event.'
                                        }
                                        action={
                                            can.manage && (
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        setInviteOpen(true)
                                                    }
                                                >
                                                    <UserPlus className="size-4" />
                                                    Invite attendees
                                                </Button>
                                            )
                                        }
                                    />
                                )}
                                {attendees.length > 0 &&
                                    matching.length === 0 && (
                                        <EmptyTableRow
                                            colSpan={can.manage ? 4 : 3}
                                            icon={Users}
                                            title="Nobody matches"
                                            description="Try another name or number."
                                        />
                                    )}
                                {roster.rows.map((attendee) => (
                                    <TableRow key={attendee.id}>
                                        <TableCell>
                                            <div className="flex min-w-0 items-center gap-2.5">
                                                <PersonAvatar
                                                    name={
                                                        attendee.employee
                                                            ?.full_name ??
                                                        'Unknown'
                                                    }
                                                    initials={
                                                        attendee.employee
                                                            ?.initials ?? '?'
                                                    }
                                                    photo={
                                                        attendee.employee?.photo
                                                    }
                                                    className="size-8"
                                                    fallbackClassName="text-[11px]"
                                                />
                                                <div className="min-w-0">
                                                    <p className="max-w-60 truncate text-sm font-medium">
                                                        {attendee.employee
                                                            ?.full_name ??
                                                            'Unknown employee'}
                                                    </p>
                                                    <p className="font-mono text-[11px] text-muted-foreground">
                                                        {attendee.employee
                                                            ?.employee_no ??
                                                            '—'}
                                                    </p>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-sm text-muted-foreground">
                                            {attendee.employee?.position ?? '—'}
                                        </TableCell>
                                        <TableCell>
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
                                                    <SelectTrigger
                                                        className="h-8 w-32"
                                                        aria-label={`Response for ${attendee.employee?.full_name ?? 'this attendee'}`}
                                                    >
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {RESPONSE_ORDER.map(
                                                            (r) => (
                                                                <SelectItem
                                                                    key={r}
                                                                    value={r}
                                                                >
                                                                    {
                                                                        RESPONSE_LABELS[
                                                                            r
                                                                        ]
                                                                    }
                                                                </SelectItem>
                                                            ),
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            ) : (
                                                <ResponseBadge
                                                    response={attendee.response}
                                                />
                                            )}
                                        </TableCell>
                                        {can.manage && (
                                            <TableCell className="text-right">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    className="size-8 text-muted-foreground hover:text-destructive"
                                                    onClick={() =>
                                                        setRemove(attendee)
                                                    }
                                                    aria-label={`Remove ${attendee.employee?.full_name ?? 'attendee'}`}
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </TableCell>
                                        )}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </DataTable>
                    </TableCard>

                    {matching.length > 0 && (
                        <TablePagination
                            meta={roster.meta}
                            perPage={roster.perPage}
                            onPage={roster.setPage}
                            onPerPage={roster.setPerPage}
                        />
                    )}
                </div>
            </PageBody>

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
                open={remindOpen}
                onOpenChange={setRemindOpen}
                title="Remind pending invitees?"
                description={`${counts.invited} ${counts.invited === 1 ? 'invitee who has' : 'invitees who have'} not responded will get an in-app reminder about "${event.title}".`}
                confirmLabel="Send reminders"
                processing={processing}
                onConfirm={remindPending}
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

EventShow.layout = (props: EventShowPageProps) => ({
    breadcrumbs: [
        { title: 'Events & Meetings', href: eventRoutes.index },
        {
            title: props.event.title,
            href: eventRoutes.show(props.event.hashid),
        },
    ],
});
