import { Link } from '@inertiajs/react';
import {
    Activity as ActivityIcon,
    ArrowUpRight,
    Briefcase,
    Building2,
    CalendarDays,
    CheckCircle2,
    ClipboardList,
    Clock3,
    Users,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { cn } from '@/lib/utils';
import { ACCENT, STATUS, TONE, timeAgo } from '../lib';
import type {
    ActivityItem,
    Attendance,
    AttentionItem,
    DashEvent,
    Recruitment,
    Workforce,
} from '../types';
import { BarList, Donut, Legend, TrendArea } from './charts';
import type { Segment } from './charts';

/** Shared card chrome: a titled, optionally-linked surface. */
export function SectionCard({
    title,
    icon,
    href,
    linkLabel = 'View',
    className,
    children,
}: {
    title: string;
    icon: ReactNode;
    href?: string;
    linkLabel?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section
            className={cn(
                'flex flex-col rounded-2xl border border-sidebar-border/70 bg-card p-5 shadow-sm dark:border-sidebar-border',
                className,
            )}
        >
            <header className="mb-4 flex items-center justify-between gap-2">
                <h2 className="flex items-center gap-2 text-sm font-semibold tracking-tight">
                    <span className="text-[#0ABFBF]">{icon}</span>
                    {title}
                </h2>
                {href && (
                    <Link
                        href={href}
                        className="flex items-center gap-0.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                    >
                        {linkLabel}
                        <ArrowUpRight className="size-3.5" />
                    </Link>
                )}
            </header>
            <div className="flex flex-1 flex-col">{children}</div>
        </section>
    );
}

/** A small labelled figure used in panel footers. */
function MiniStat({ value, label }: { value: ReactNode; label: string }) {
    return (
        <div>
            <p className="text-lg font-semibold tracking-tight tabular-nums">
                {value}
            </p>
            <p className="text-xs text-muted-foreground">{label}</p>
        </div>
    );
}

export function WorkforcePanel({ workforce }: { workforce: Workforce }) {
    const palette = [ACCENT.teal, ACCENT.indigo, ACCENT.amber, ACCENT.slate];
    const segments: Segment[] = workforce.composition.map((slice, i) => ({
        label: slice.label,
        value: slice.count,
        color: palette[i % palette.length],
    }));

    return (
        <SectionCard
            title="Workforce"
            icon={<Users className="size-4" />}
            href="/employees"
        >
            <div className="flex flex-wrap items-center gap-5">
                <Donut
                    segments={segments}
                    centerValue={workforce.active.toLocaleString()}
                    centerLabel="active"
                />
                <div className="min-w-[140px] flex-1">
                    <Legend segments={segments} />
                </div>
            </div>
            <div className="mt-5 grid grid-cols-3 gap-3 border-t border-border pt-4">
                <MiniStat value={workforce.departments} label="Departments" />
                <MiniStat
                    value={
                        <span className="text-[#0ABFBF]">
                            +{workforce.new_this_month}
                        </span>
                    }
                    label="New this month"
                />
                <MiniStat value={workforce.on_leave} label="On leave" />
            </div>
        </SectionCard>
    );
}

export function AttendancePanel({ attendance }: { attendance: Attendance }) {
    const chips = [
        { label: 'In', value: attendance.present, color: STATUS.present },
        { label: 'Late', value: attendance.late, color: STATUS.late },
        { label: 'Absent', value: attendance.absent, color: STATUS.absent },
        {
            label: 'On leave',
            value: attendance.on_leave,
            color: STATUS.on_leave,
        },
    ];

    return (
        <SectionCard
            title="Attendance"
            icon={<Clock3 className="size-4" />}
            href="/attendance"
        >
            <div className="mb-1 flex items-baseline justify-between">
                <p className="text-xs text-muted-foreground">
                    Present, last 14 days
                </p>
                <p className="text-xs text-muted-foreground">
                    avg{' '}
                    <span className="font-semibold text-foreground tabular-nums">
                        {attendance.avg_hours}h
                    </span>
                </p>
            </div>
            <TrendArea data={attendance.trend} />
            <div className="mt-4 grid grid-cols-4 gap-2 border-t border-border pt-4">
                {chips.map((chip) => (
                    <div key={chip.label} className="flex flex-col gap-1">
                        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <span
                                className="size-2 rounded-full"
                                style={{ background: chip.color }}
                            />
                            {chip.label}
                        </span>
                        <span className="text-base font-semibold tabular-nums">
                            {chip.value}
                        </span>
                    </div>
                ))}
            </div>
        </SectionCard>
    );
}

export function RecruitmentPanel({
    recruitment,
}: {
    recruitment: Recruitment;
}) {
    const bars = [
        { label: 'Applicants', value: recruitment.total_applicants },
        { label: 'In pipeline', value: recruitment.in_pipeline },
        { label: 'In final stage', value: recruitment.final_stage },
        { label: 'Hired this month', value: recruitment.hired_this_month },
    ];

    return (
        <SectionCard
            title="Recruitment"
            icon={<Briefcase className="size-4" />}
            href="/recruitment"
        >
            <div className="mb-4 flex items-end gap-2">
                <span className="text-3xl font-semibold tracking-tight tabular-nums">
                    {recruitment.open_postings}
                </span>
                <span className="pb-1 text-sm text-muted-foreground">
                    open roles
                </span>
            </div>
            <BarList bars={bars} />
            {recruitment.interviews_upcoming > 0 && (
                <p className="mt-4 border-t border-border pt-3 text-xs text-muted-foreground">
                    <span className="font-semibold text-foreground">
                        {recruitment.interviews_upcoming}
                    </span>{' '}
                    interview{recruitment.interviews_upcoming === 1 ? '' : 's'}{' '}
                    scheduled ahead
                </p>
            )}
        </SectionCard>
    );
}

export function DepartmentsPanel({ workforce }: { workforce: Workforce }) {
    const bars = workforce.top_departments
        .filter((d) => d.count > 0)
        .map((d) => ({ label: d.name, value: d.count }));

    return (
        <SectionCard
            title="Headcount by department"
            icon={<Building2 className="size-4" />}
            href="/setup/departments"
        >
            {bars.length > 0 ? (
                <BarList bars={bars} color={ACCENT.indigo} />
            ) : (
                <EmptyHint>No departments staffed yet.</EmptyHint>
            )}
        </SectionCard>
    );
}

export function AttentionPanel({ items }: { items: AttentionItem[] }) {
    return (
        <SectionCard
            title="Needs your attention"
            icon={<ClipboardList className="size-4" />}
        >
            {items.length === 0 ? (
                <div className="flex flex-1 flex-col items-center justify-center gap-2 py-6 text-center">
                    <CheckCircle2 className="size-7 text-emerald-500" />
                    <p className="text-sm font-medium">You're all caught up</p>
                    <p className="text-xs text-muted-foreground">
                        Nothing is waiting on you right now.
                    </p>
                </div>
            ) : (
                <ul className="flex flex-col gap-1">
                    {items.map((item) => {
                        const tone = TONE[item.tone];

                        return (
                            <li key={item.key}>
                                <Link
                                    href={item.href}
                                    className="group flex items-center gap-3 rounded-lg px-2 py-2 transition-colors hover:bg-muted/60"
                                >
                                    <span
                                        className={cn(
                                            'flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold tabular-nums ring-1',
                                            tone.text,
                                            tone.ring,
                                        )}
                                    >
                                        {item.count}
                                    </span>
                                    <span className="flex-1 text-sm">
                                        {item.label}
                                    </span>
                                    <ArrowUpRight className="size-4 text-muted-foreground/60 transition-colors group-hover:text-foreground" />
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
        </SectionCard>
    );
}

export function EventsPanel({ events }: { events: DashEvent[] }) {
    return (
        <SectionCard
            title="Upcoming"
            icon={<CalendarDays className="size-4" />}
            href="/events"
        >
            {events.length === 0 ? (
                <EmptyHint>Nothing scheduled ahead.</EmptyHint>
            ) : (
                <ul className="flex flex-col gap-3">
                    {events.map((event) => {
                        const date = event.starts_at
                            ? new Date(event.starts_at)
                            : null;

                        return (
                            <li
                                key={event.id}
                                className="flex items-center gap-3"
                            >
                                <div className="flex size-11 shrink-0 flex-col items-center justify-center rounded-lg bg-[#0ABFBF]/10 leading-none text-[#0ABFBF]">
                                    <span className="text-[10px] font-semibold tracking-wide uppercase">
                                        {date?.toLocaleDateString(undefined, {
                                            month: 'short',
                                        })}
                                    </span>
                                    <span className="text-base font-bold tabular-nums">
                                        {date?.getDate()}
                                    </span>
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">
                                        {event.title}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {date?.toLocaleTimeString(undefined, {
                                            hour: 'numeric',
                                            minute: '2-digit',
                                        })}
                                        {event.location
                                            ? ` · ${event.location}`
                                            : ''}
                                    </p>
                                </div>
                            </li>
                        );
                    })}
                </ul>
            )}
        </SectionCard>
    );
}

export function ActivityPanel({ activity }: { activity: ActivityItem[] }) {
    return (
        <SectionCard
            title="Recent activity"
            icon={<ActivityIcon className="size-4" />}
            href="/system/activity-logs"
        >
            {activity.length === 0 ? (
                <EmptyHint>No activity logged yet.</EmptyHint>
            ) : (
                <ul className="flex flex-col gap-3">
                    {activity.map((entry) => (
                        <li key={entry.id} className="flex items-start gap-3">
                            <PersonAvatar
                                name={entry.causer?.name ?? 'System'}
                                initials={entry.causer?.initials ?? 'SY'}
                                photo={entry.causer?.avatar ?? null}
                                className="mt-0.5 size-7"
                            />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm leading-snug">
                                    <span className="font-medium">
                                        {entry.causer?.name ?? 'System'}
                                    </span>{' '}
                                    <span className="text-muted-foreground">
                                        {entry.description ??
                                            entry.event ??
                                            'made a change'}
                                    </span>
                                </p>
                                <p className="text-xs text-muted-foreground/70">
                                    {timeAgo(entry.created_at)}
                                </p>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </SectionCard>
    );
}

function EmptyHint({ children }: { children: ReactNode }) {
    return (
        <p className="flex flex-1 items-center justify-center py-6 text-center text-sm text-muted-foreground">
            {children}
        </p>
    );
}
