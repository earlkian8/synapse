import { Head, usePage } from '@inertiajs/react';
import {
    BellRing,
    CalendarClock,
    CalendarPlus,
    CalendarX2,
    DoorOpen,
    MapPin,
    Repeat,
    UserRound,
    Users,
    Video,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import { Checkbox } from '@/components/ui/checkbox';
import { EventStatusBadge } from '@/features/events/components/event-status-badge';
import { EventsNav } from '@/features/events/components/events-nav';
import { RespondButtons } from '@/features/events/components/respond-buttons';
import { SubscribeButton } from '@/features/events/components/subscribe-dialog';
import { formatTime, reminderLabel } from '@/features/events/constants';
import { eventRoutes } from '@/features/events/routes';
import type { MyEventsPageProps, MyInvitation } from '@/features/events/types';
import { cn } from '@/lib/utils';

type View = 'upcoming' | 'past';

/** Invitations grouped by the day they start, in the order given. */
function byDay(
    invitations: MyInvitation[],
): { day: Date; items: MyInvitation[] }[] {
    const groups: { day: Date; items: MyInvitation[] }[] = [];

    for (const invitation of invitations) {
        const start = new Date(invitation.event.starts_at ?? 0);
        const last = groups.at(-1);

        if (last && last.day.toDateString() === start.toDateString()) {
            last.items.push(invitation);
        } else {
            groups.push({ day: start, items: [invitation] });
        }
    }

    return groups;
}

/**
 * My events (ADR 0070): the person's own invitations as an agenda — a date
 * rail, and for each event when, where, who runs it and how many are coming,
 * with a one-tap answer. A notification opens this page on its event.
 */
export default function MyEvents() {
    const { invitations, focus, has_employee } =
        usePage<MyEventsPageProps>().props;

    const focused = invitations.find((i) => i.event.hashid === focus);
    const [view, setView] = useState<View>(
        focused?.event.status === 'past' ? 'past' : 'upcoming',
    );

    const upcoming = useMemo(
        () => invitations.filter((i) => i.event.status !== 'past'),
        [invitations],
    );
    const past = useMemo(
        () => invitations.filter((i) => i.event.status === 'past'),
        [invitations],
    );
    const waiting = upcoming.filter((i) => i.response === 'invited').length;
    const repeats = upcoming.some((i) => i.event.series);
    const [everyDate, setEveryDate] = useState(false);

    // A repeating event shows its next date only, unless every date is asked
    // for — or a notice pointed at a later one.
    const { shown, later } = useMemo(() => {
        const list = view === 'upcoming' ? upcoming : past;

        if (view === 'past' || everyDate) {
            return { shown: list, later: new Map<number, number>() };
        }

        const seen = new Set<number>();
        const hidden = new Map<number, number>();
        const kept = list.filter((invitation) => {
            const series = invitation.event.series?.id;

            if (
                series === undefined ||
                !seen.has(series) ||
                invitation.event.hashid === focus
            ) {
                if (series !== undefined) {
                    seen.add(series);
                }

                return true;
            }

            hidden.set(series, (hidden.get(series) ?? 0) + 1);

            return false;
        });

        return { shown: kept, later: hidden };
    }, [view, upcoming, past, everyDate, focus]);
    const groups = useMemo(() => byDay(shown), [shown]);

    // The event a notification pointed at: bring it into view once.
    useEffect(() => {
        if (focus) {
            document
                .getElementById(`event-${focus}`)
                ?.scrollIntoView({ block: 'center' });
        }
    }, [focus]);

    return (
        <>
            <Head title="My invitations" />

            <PageBody>
                <PageHeader
                    title="My invitations"
                    description={
                        waiting > 0
                            ? `${waiting} ${waiting === 1 ? 'invitation is' : 'invitations are'} waiting for your answer.`
                            : 'Your invitations. Your answer goes straight to the organiser.'
                    }
                    actions={<EventsNav current="me" />}
                />

                {!has_employee ? (
                    <EmptyAgenda
                        title="Your account isn’t linked to an employee"
                        body="Invitations go to employees. Ask HR to link your account to your employee record."
                    />
                ) : (
                    <>
                        <div className="flex flex-col gap-2 border-b border-border sm:flex-row sm:items-end sm:justify-between">
                            <div
                                role="tablist"
                                aria-label="Which events"
                                className="flex items-center gap-1 overflow-x-auto"
                            >
                                {(['upcoming', 'past'] as View[]).map(
                                    (option) => (
                                        <button
                                            key={option}
                                            type="button"
                                            role="tab"
                                            aria-selected={view === option}
                                            onClick={() => setView(option)}
                                            className={cn(
                                                'border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                                view === option
                                                    ? 'border-[#0ABFBF] text-foreground'
                                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                                            )}
                                        >
                                            {option === 'upcoming'
                                                ? 'Coming up'
                                                : 'Past 30 days'}
                                            <span className="ml-1.5 text-muted-foreground tabular-nums">
                                                {option === 'upcoming'
                                                    ? upcoming.length
                                                    : past.length}
                                            </span>
                                        </button>
                                    ),
                                )}
                            </div>

                            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 pb-2">
                                {view === 'upcoming' && repeats && (
                                    <label className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Checkbox
                                            checked={everyDate}
                                            onCheckedChange={(checked) =>
                                                setEveryDate(checked === true)
                                            }
                                        />
                                        Show every date of repeating events
                                    </label>
                                )}
                                <SubscribeButton />
                            </div>
                        </div>

                        {groups.length === 0 ? (
                            view === 'upcoming' ? (
                                <EmptyAgenda
                                    title="Nothing coming up"
                                    body="When you’re invited to an event or a meeting, it lands here and in your notifications."
                                />
                            ) : (
                                <EmptyAgenda
                                    title="Nothing in the past 30 days"
                                    body="Events you were invited to show here for a month after."
                                />
                            )
                        ) : (
                            <ol className="flex flex-col gap-6">
                                {groups.map((group) => (
                                    <li
                                        key={group.day.toDateString()}
                                        className="grid grid-cols-1 gap-3 sm:grid-cols-[4.5rem_1fr] sm:gap-5"
                                    >
                                        <DateRail day={group.day} />
                                        <ul className="flex flex-col gap-3">
                                            {group.items.map((invitation) => (
                                                <InvitationCard
                                                    key={invitation.id}
                                                    invitation={invitation}
                                                    later={
                                                        invitation.event.series
                                                            ? (later.get(
                                                                  invitation
                                                                      .event
                                                                      .series
                                                                      .id,
                                                              ) ?? 0)
                                                            : 0
                                                    }
                                                    focused={
                                                        invitation.event
                                                            .hashid === focus
                                                    }
                                                />
                                            ))}
                                        </ul>
                                    </li>
                                ))}
                            </ol>
                        )}
                    </>
                )}
            </PageBody>
        </>
    );
}

/** The day, set large: the rail the agenda hangs on. */
function DateRail({ day }: { day: Date }) {
    const isToday = day.toDateString() === new Date().toDateString();

    return (
        <div className="flex items-baseline gap-2 sm:sticky sm:top-4 sm:flex-col sm:items-start sm:gap-0 sm:self-start">
            <span
                className={cn(
                    'text-xs font-medium',
                    isToday
                        ? 'text-[#08767c] dark:text-[#0ABFBF]'
                        : 'text-muted-foreground',
                )}
            >
                {isToday
                    ? 'Today'
                    : day.toLocaleDateString(undefined, { weekday: 'short' })}
            </span>
            <span className="text-3xl leading-none font-semibold tracking-tight tabular-nums">
                {day.getDate()}
            </span>
            <span className="text-xs text-muted-foreground">
                {day.toLocaleDateString(undefined, {
                    month: 'short',
                    year:
                        day.getFullYear() === new Date().getFullYear()
                            ? undefined
                            : 'numeric',
                })}
            </span>
        </div>
    );
}

function InvitationCard({
    invitation,
    focused,
    later,
}: {
    invitation: MyInvitation;
    focused: boolean;
    /** Later dates of its series not shown on the page. */
    later: number;
}) {
    const { event } = invitation;
    const TypeIcon = event.type === 'meeting' ? Video : CalendarClock;
    const where = event.room
        ? [event.room.name, event.room.location].filter(Boolean).join(', ')
        : event.location;
    const reminder = reminderLabel(event.reminder_minutes);

    return (
        <li
            id={`event-${event.hashid}`}
            className={cn(
                'scroll-mt-24 rounded-xl border bg-card p-4 transition-shadow',
                focused
                    ? 'border-[#0ABFBF] ring-2 ring-[#0ABFBF]/30'
                    : 'border-sidebar-border/70 dark:border-sidebar-border',
            )}
        >
            <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                <div className="flex min-w-0 flex-col gap-1.5">
                    <div className="flex flex-wrap items-center gap-2">
                        <TypeIcon
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden
                        />
                        <h2 className="text-base font-semibold">
                            {event.title}
                        </h2>
                        {event.status === 'ongoing' && (
                            <EventStatusBadge status="ongoing" />
                        )}
                    </div>

                    <p className="text-sm tabular-nums">
                        {formatTime(event.starts_at)}
                        {event.ends_at && ` – ${formatTime(event.ends_at)}`}
                        {event.ends_at &&
                            event.starts_at &&
                            new Date(event.ends_at).toDateString() !==
                                new Date(event.starts_at).toDateString() &&
                            ` (${new Date(event.ends_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric' })})`}
                    </p>

                    <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                        {where && (
                            <li className="inline-flex items-center gap-1">
                                {event.room ? (
                                    <DoorOpen className="size-3.5" />
                                ) : (
                                    <MapPin className="size-3.5" />
                                )}
                                {where}
                            </li>
                        )}
                        {event.organizer && (
                            <li className="inline-flex items-center gap-1">
                                <UserRound className="size-3.5" />
                                {event.organizer}
                            </li>
                        )}
                        <li className="inline-flex items-center gap-1">
                            <Users className="size-3.5" />
                            {event.attending_count} of {event.attendees_count}{' '}
                            coming
                        </li>
                        {event.series && (
                            <li className="inline-flex items-center gap-1">
                                <Repeat className="size-3.5" />
                                {event.series.summary}
                                {later > 0 &&
                                    `, and ${later} later ${later === 1 ? 'date' : 'dates'}`}
                            </li>
                        )}
                        {reminder && event.status === 'upcoming' && (
                            <li className="inline-flex items-center gap-1">
                                <BellRing className="size-3.5" />
                                Reminder {reminder}
                            </li>
                        )}
                    </ul>

                    {event.description && (
                        <p className="line-clamp-2 max-w-2xl text-sm text-muted-foreground">
                            {event.description}
                        </p>
                    )}
                </div>

                <div className="flex shrink-0 flex-col gap-2 lg:items-end">
                    <RespondButtons invitation={invitation} />
                    <a
                        href={eventRoutes.meIcs(event.hashid)}
                        className="inline-flex items-center gap-1 self-start text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline lg:self-end"
                    >
                        <CalendarPlus className="size-3.5" />
                        Add to calendar
                    </a>
                </div>
            </div>
        </li>
    );
}

function EmptyAgenda({ title, body }: { title: string; body: string }) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-14 text-center">
            <CalendarX2 className="size-8 text-muted-foreground" aria-hidden />
            <p className="font-medium">{title}</p>
            <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
        </div>
    );
}

MyEvents.layout = {
    breadcrumbs: [
        { title: 'Events & Meetings', href: '/events' },
        { title: 'My invitations', href: '/events/me' },
    ],
};
