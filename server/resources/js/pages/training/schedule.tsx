import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarX2, ChevronLeft, ChevronRight, MapPin } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    FilterSelect,
    PageBody,
    PageHeader,
    TableCard,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { TrainingNav } from '@/features/training/components/training-nav';
import {
    formatClock,
    formatDateRange,
    formatSessionTime,
} from '@/features/training/constants';
import { trainingRoutes } from '@/features/training/routes';
import type {
    ScheduledSession,
    SchedulePageProps,
} from '@/features/training/types';
import { cn } from '@/lib/utils';

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** How many sessions a day cell lists before "+n more". */
const PER_CELL = 3;

function iso(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** "2026-10" moved by some months. */
function shiftMonth(month: string, by: number): string {
    const [y, m] = month.split('-').map(Number);
    const date = new Date(y, m - 1 + by, 1);

    return iso(date).slice(0, 7);
}

/** The weeks shown for a month: Monday-first, whole weeks. */
function weeksOf(month: string): Date[][] {
    const [y, m] = month.split('-').map(Number);
    const first = new Date(y, m - 1, 1);
    const start = new Date(first);
    start.setDate(first.getDate() - ((first.getDay() + 6) % 7));

    const weeks: Date[][] = [];
    const cursor = new Date(start);

    do {
        const week: Date[] = [];

        for (let i = 0; i < 7; i++) {
            week.push(new Date(cursor));
            cursor.setDate(cursor.getDate() + 1);
        }

        weeks.push(week);
    } while (cursor.getMonth() === m - 1);

    return weeks;
}

/**
 * The training calendar (ADR 0074): every session of every program in a month,
 * Monday first, on the company's clock. A held session no one has taken
 * attendance at is flagged, and programs running this month with no session
 * yet are listed under it, so a gap shows. On a phone it reads as an agenda.
 */
export default function TrainingSchedule() {
    const { month, today, sessions, unscheduled, nav } =
        usePage<SchedulePageProps>().props;

    const [program, setProgram] = useState('all');

    const programs = useMemo(() => {
        const seen = new Map<string, string>();

        for (const s of sessions) {
            seen.set(s.program.hashid, s.program.name);
        }

        return [...seen.entries()].sort((a, b) => a[1].localeCompare(b[1]));
    }, [sessions]);

    const shown = useMemo(
        () =>
            program === 'all'
                ? sessions
                : sessions.filter((s) => s.program.hashid === program),
        [sessions, program],
    );

    const byDay = useMemo(() => {
        const days = new Map<string, ScheduledSession[]>();

        for (const s of shown) {
            days.set(s.session_date, [...(days.get(s.session_date) ?? []), s]);
        }

        return days;
    }, [shown]);

    const weeks = useMemo(() => weeksOf(month), [month]);
    const [y, m] = month.split('-').map(Number);
    const title = new Date(y, m - 1, 1).toLocaleDateString(undefined, {
        month: 'long',
        year: 'numeric',
    });
    const notTaken = shown.filter((s) => s.is_held && s.marked === 0).length;

    const go = (to: string) =>
        router.get(trainingRoutes.schedule(to), {}, { preserveScroll: true });

    return (
        <>
            <Head title="Training schedule" />

            <PageBody>
                <PageHeader
                    title="Schedule"
                    description={
                        notTaken > 0
                            ? `${notTaken} ${notTaken === 1 ? 'session has' : 'sessions have'} no attendance taken yet.`
                            : 'Every session of every program, month by month.'
                    }
                    actions={<TrainingNav current="schedule" counts={nav} />}
                />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="icon"
                            className="size-8"
                            onClick={() => go(shiftMonth(month, -1))}
                            aria-label="Previous month"
                        >
                            <ChevronLeft className="size-4" />
                        </Button>
                        <h2 className="min-w-36 text-center text-base font-semibold tabular-nums">
                            {title}
                        </h2>
                        <Button
                            variant="outline"
                            size="icon"
                            className="size-8"
                            onClick={() => go(shiftMonth(month, 1))}
                            aria-label="Next month"
                        >
                            <ChevronRight className="size-4" />
                        </Button>
                        {month !== today.slice(0, 7) && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => go(today.slice(0, 7))}
                            >
                                Today
                            </Button>
                        )}
                    </div>
                    {programs.length > 1 && (
                        <FilterSelect
                            label="Filter by program"
                            value={program}
                            onChange={setProgram}
                            options={[
                                { value: 'all', label: 'All programs' },
                                ...programs.map(([hashid, name]) => ({
                                    value: hashid,
                                    label: name,
                                })),
                            ]}
                            className="w-56"
                        />
                    )}
                </div>

                {/* The month grid, from a tablet up. */}
                <div className="hidden overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm sm:block dark:border-sidebar-border">
                    <div className="grid grid-cols-7 border-b border-border bg-muted/30">
                        {WEEKDAYS.map((day) => (
                            <div
                                key={day}
                                className="px-2 py-2 text-center text-[11px] font-semibold tracking-wide text-muted-foreground uppercase"
                            >
                                {day}
                            </div>
                        ))}
                    </div>
                    {weeks.map((week) => (
                        <div
                            key={iso(week[0])}
                            className="grid grid-cols-7 border-b border-border last:border-b-0"
                        >
                            {week.map((day) => {
                                const key = iso(day);
                                const inMonth = day.getMonth() === m - 1;
                                const items = byDay.get(key) ?? [];
                                const isToday = key === today;

                                return (
                                    <div
                                        key={key}
                                        className={cn(
                                            'flex min-h-28 min-w-0 flex-col gap-1 border-r border-border p-1.5 last:border-r-0',
                                            !inMonth && 'bg-muted/20',
                                            isToday && 'bg-[#0ABFBF]/5',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'self-start rounded-md px-1.5 text-xs tabular-nums',
                                                isToday
                                                    ? 'bg-[#0ABFBF] font-semibold text-white'
                                                    : inMonth
                                                      ? 'text-foreground'
                                                      : 'text-muted-foreground/60',
                                            )}
                                        >
                                            {day.getDate()}
                                        </span>
                                        {items.slice(0, PER_CELL).map((s) => (
                                            <SessionChip
                                                key={s.id}
                                                session={s}
                                            />
                                        ))}
                                        {items.length > PER_CELL && (
                                            <span className="px-1 text-[11px] text-muted-foreground">
                                                +{items.length - PER_CELL} more
                                            </span>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    ))}
                </div>

                {/* The same month as an agenda, on a phone. */}
                <div className="sm:hidden">
                    {byDay.size === 0 ? (
                        <EmptyMonth />
                    ) : (
                        <ol className="flex flex-col gap-4">
                            {[...byDay.entries()].map(([day, items]) => (
                                <li key={day} className="flex flex-col gap-2">
                                    <p
                                        className={cn(
                                            'text-xs font-semibold',
                                            day === today
                                                ? 'text-[#08767c] dark:text-[#0ABFBF]'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {day === today
                                            ? 'Today'
                                            : new Date(
                                                  `${day}T00:00:00`,
                                              ).toLocaleDateString(undefined, {
                                                  weekday: 'long',
                                                  month: 'short',
                                                  day: 'numeric',
                                              })}
                                    </p>
                                    {items.map((s) => (
                                        <AgendaRow key={s.id} session={s} />
                                    ))}
                                </li>
                            ))}
                        </ol>
                    )}
                </div>

                {sessions.length === 0 && (
                    <div className="hidden sm:block">
                        <EmptyMonth />
                    </div>
                )}

                {unscheduled.length > 0 && (
                    <TableCard
                        title="Running with no sessions"
                        count={unscheduled.length}
                        description="Programs this month that have no sessions scheduled yet."
                    >
                        <ul className="divide-y divide-border">
                            {unscheduled.map((p) => (
                                <li key={p.hashid}>
                                    <Link
                                        href={`${trainingRoutes.show(p.hashid)}?tab=sessions`}
                                        className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm hover:bg-muted/40"
                                    >
                                        <span className="font-medium">
                                            {p.name}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {formatDateRange(
                                                p.start_date,
                                                p.end_date,
                                            )}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </TableCard>
                )}
            </PageBody>
        </>
    );
}

/** A session in a day cell: its start, its program, and a flag if overdue. */
function SessionChip({ session }: { session: ScheduledSession }) {
    const overdue = session.is_held && session.marked === 0;

    return (
        <Link
            href={`${trainingRoutes.show(session.program.hashid)}?tab=sessions`}
            title={`${session.program.name}${session.title ? ` — ${session.title}` : ''}, ${formatSessionTime(session.starts_at, session.ends_at)}${overdue ? ' · attendance not taken' : ''}`}
            className={cn(
                'flex min-w-0 items-center gap-1 rounded-r-md border-l-2 px-1.5 py-0.5 text-[11px] leading-tight transition-colors',
                session.is_held
                    ? 'border-l-muted-foreground/40 bg-muted/50 text-muted-foreground hover:bg-muted'
                    : 'border-l-[#0ABFBF] bg-[#0ABFBF]/10 text-foreground hover:bg-[#0ABFBF]/15',
            )}
        >
            <span className="shrink-0 tabular-nums">
                {formatClock(session.starts_at)}
            </span>
            <span className="truncate font-medium">{session.program.name}</span>
            {overdue && (
                <span
                    className="ml-auto size-1.5 shrink-0 rounded-full bg-amber-500"
                    aria-label="Attendance not taken"
                />
            )}
        </Link>
    );
}

function AgendaRow({ session }: { session: ScheduledSession }) {
    const overdue = session.is_held && session.marked === 0;

    return (
        <Link
            href={`${trainingRoutes.show(session.program.hashid)}?tab=sessions`}
            className="flex flex-col gap-0.5 rounded-xl border border-sidebar-border/70 bg-card px-3.5 py-2.5 dark:border-sidebar-border"
        >
            <span className="text-sm font-medium">
                {session.program.name}
                {session.title && (
                    <span className="font-normal text-muted-foreground">
                        {' '}
                        · {session.title}
                    </span>
                )}
            </span>
            <span className="flex flex-wrap gap-x-3 text-xs text-muted-foreground tabular-nums">
                {formatSessionTime(session.starts_at, session.ends_at)}
                {session.location && (
                    <span className="inline-flex items-center gap-1">
                        <MapPin className="size-3" />
                        {session.location}
                    </span>
                )}
                {overdue && (
                    <span className="text-amber-600 dark:text-amber-400">
                        Attendance not taken
                    </span>
                )}
            </span>
        </Link>
    );
}

function EmptyMonth() {
    return (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-12 text-center">
            <CalendarX2 className="size-8 text-muted-foreground" aria-hidden />
            <p className="font-medium">No sessions this month</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Schedule sessions from a program’s Sessions tab.
            </p>
        </div>
    );
}

TrainingSchedule.layout = {
    breadcrumbs: [
        { title: 'Training & Development', href: '/training' },
        { title: 'Schedule', href: '/training/schedule' },
    ],
};
