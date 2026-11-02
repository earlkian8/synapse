import {
    Award,
    DoorOpen,
    GraduationCap,
    Plane,
    TrendingUp,
} from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { shortDate } from '../lib';
import type { AttendanceMonth, PredictionContext } from '../types';

const OFFBOARDING_TYPES: Record<string, string> = {
    resignation: 'A resignation',
    termination: 'A termination',
    retirement: 'A retirement',
    end_of_contract: 'An end of contract',
};

const EMPLOYMENT_TYPES: Record<string, string> = {
    regular: 'Regular',
    probationary: 'Probationary',
    part_time: 'Part-time',
    contractual: 'Contractual',
};

/** "2 yrs 3 mos" / "8 mos". */
export function formatTenure(years: number | null): string {
    if (years === null) {
        return '—';
    }

    const months = Math.round(years * 12);
    const y = Math.floor(months / 12);
    const m = months % 12;
    const parts = [
        y > 0 ? `${y} ${y === 1 ? 'yr' : 'yrs'}` : '',
        m > 0 || y === 0 ? `${m} ${m === 1 ? 'mo' : 'mos'}` : '',
    ];

    return parts.filter(Boolean).join(' ');
}

/** A case already under way: the prediction is overtaken by events. */
export function OffboardingNotice({
    offboarding,
}: {
    offboarding: NonNullable<PredictionContext['offboarding']>;
}) {
    return (
        <div className="flex items-start gap-3 rounded-xl border border-sidebar-border/70 bg-muted/40 px-4 py-3 dark:border-sidebar-border">
            <DoorOpen className="mt-0.5 size-4 shrink-0 text-foreground/70" />
            <p className="text-sm">
                <span className="font-medium">
                    {OFFBOARDING_TYPES[offboarding.type] ?? 'An exit'} is
                    already in progress
                </span>
                {offboarding.notice_date
                    ? `, filed ${shortDate(offboarding.notice_date)}`
                    : ''}
                {offboarding.last_working_day
                    ? `; last working day ${shortDate(offboarding.last_working_day)}`
                    : ''}
                .
            </p>
        </div>
    );
}

/** A small titled panel in the Record tab. */
function Panel({
    title,
    icon: Icon,
    aside,
    children,
    className,
}: {
    title: string;
    icon?: typeof Award;
    aside?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <section
            className={cn(
                'flex flex-col gap-2.5 rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border',
                className,
            )}
        >
            <div className="flex items-baseline justify-between gap-3">
                <h3 className="flex items-center gap-1.5 text-sm font-semibold">
                    {Icon && (
                        <Icon className="size-3.5 text-muted-foreground" />
                    )}
                    {title}
                </h3>
                {aside && (
                    <span className="text-xs text-muted-foreground">
                        {aside}
                    </span>
                )}
            </div>
            {children}
        </section>
    );
}

/** A plain list of label → value pairs. */
function Facts({ rows }: { rows: [string, ReactNode][] }) {
    return (
        <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1.5 text-sm">
            {rows.map(([label, value]) => (
                <div key={label} className="contents">
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd className="truncate text-right font-medium tabular-nums">
                        {value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

function Quiet({ children }: { children: ReactNode }) {
    return <p className="text-sm text-muted-foreground">{children}</p>;
}

/**
 * Attendance month by month — absences, late arrivals and overtime, the counts the
 * attrition model reads over 90 days — as three rows of one bar per month, so a
 * change shows. Each row has its own scale and the number under every bar.
 */
export function AttendanceMonths({ months }: { months: AttendanceMonth[] }) {
    const tracked = months.some((m) => m.tracked > 0);

    if (!tracked) {
        return (
            <Panel title="Attendance, last 6 months">
                <Quiet>
                    No attendance was tracked for this person in the last six
                    months.
                </Quiet>
            </Panel>
        );
    }

    const rows: {
        key: keyof AttendanceMonth;
        label: string;
        unit: (n: number) => string;
    }[] = [
        {
            key: 'absences',
            label: 'Absences',
            unit: (n) => `${n} ${n === 1 ? 'day' : 'days'} absent`,
        },
        {
            key: 'lates',
            label: 'Late arrivals',
            unit: (n) => `${n} late ${n === 1 ? 'arrival' : 'arrivals'}`,
        },
        {
            key: 'overtime_hours',
            label: 'Overtime (h)',
            unit: (n) => `${n} h overtime`,
        },
    ];

    return (
        <Panel
            title="Attendance, last 6 months"
            aside={`${months.reduce((sum, m) => sum + m.worked, 0)} days worked`}
        >
            <div className="grid grid-cols-[5.5rem_repeat(6,minmax(0,1fr))] items-end gap-x-1.5 gap-y-1">
                {rows.map((row) => {
                    const max = Math.max(
                        1,
                        ...months.map((m) => Number(m[row.key])),
                    );

                    return (
                        <div key={row.key} className="contents">
                            <span className="self-center text-xs text-muted-foreground">
                                {row.label}
                            </span>
                            {months.map((m) => {
                                const value = Number(m[row.key]);

                                return (
                                    <Tooltip key={m.month}>
                                        <TooltipTrigger asChild>
                                            <div
                                                tabIndex={0}
                                                aria-label={`${m.label}: ${row.unit(value)}`}
                                                className="flex h-10 flex-col items-center justify-end gap-0.5 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            >
                                                <span className="text-[10px] leading-none text-muted-foreground tabular-nums">
                                                    {m.tracked > 0
                                                        ? value
                                                        : '–'}
                                                </span>
                                                <span
                                                    className={cn(
                                                        'w-full max-w-5 rounded-t-[3px]',
                                                        value > 0
                                                            ? 'bg-foreground/45'
                                                            : 'bg-border',
                                                    )}
                                                    style={{
                                                        height:
                                                            value > 0
                                                                ? `${Math.max(3, (value / max) * 24)}px`
                                                                : '2px',
                                                    }}
                                                />
                                            </div>
                                        </TooltipTrigger>
                                        <TooltipContent side="top">
                                            <p className="font-semibold">
                                                {m.label}: {row.unit(value)}
                                            </p>
                                            <p className="opacity-80">
                                                {m.tracked} days on record
                                                {row.key === 'lates' &&
                                                m.late_minutes > 0
                                                    ? ` · ${m.late_minutes} min late in all`
                                                    : ''}
                                            </p>
                                        </TooltipContent>
                                    </Tooltip>
                                );
                            })}
                        </div>
                    );
                })}
                <span />
                {months.map((m) => (
                    <span
                        key={m.month}
                        className="text-center text-[10px] text-muted-foreground"
                    >
                        {m.label}
                    </span>
                ))}
            </div>
        </Panel>
    );
}

/**
 * Completed appraisals, oldest first, and the latest one by section — which part
 * of the work carried the result, and which held it back.
 */
export function AppraisalPanel({
    appraisals,
}: {
    appraisals: NonNullable<PredictionContext['appraisals']>;
}) {
    if (appraisals.length === 0) {
        return (
            <Panel title="Appraisals" icon={TrendingUp}>
                <Quiet>No completed appraisal on record.</Quiet>
            </Panel>
        );
    }

    const latest = appraisals.at(-1)!;
    const lowest =
        latest.sections.length > 1
            ? latest.sections.reduce((a, b) => (b.percent < a.percent ? b : a))
            : null;

    return (
        <Panel
            title="Appraisals"
            icon={TrendingUp}
            aside={`${appraisals.length} completed`}
        >
            <ol className="flex flex-col gap-1">
                {appraisals.map((a, i) => {
                    const previous = i > 0 ? appraisals[i - 1].percent : null;
                    const change =
                        previous === null ? null : a.percent - previous;

                    return (
                        <li
                            key={`${a.period}-${i}`}
                            className="grid grid-cols-[minmax(0,1fr)_3.5rem_3.5rem] items-baseline gap-2 text-sm"
                        >
                            <span className="truncate">
                                {a.period ?? 'Appraisal'}
                                {a.result && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {a.result}
                                    </span>
                                )}
                            </span>
                            <span className="text-right font-medium tabular-nums">
                                {a.percent.toFixed(1)}%
                            </span>
                            <span className="text-right text-xs text-muted-foreground tabular-nums">
                                {change === null
                                    ? ''
                                    : `${change >= 0 ? '+' : '−'}${Math.abs(change).toFixed(1)}`}
                            </span>
                        </li>
                    );
                })}
            </ol>

            {latest.sections.length > 0 && (
                <div className="flex flex-col gap-1.5 border-t border-border pt-2.5">
                    <span className="text-xs text-muted-foreground">
                        {latest.period ?? 'Latest'} by section
                    </span>
                    {latest.sections.map((s) => (
                        <div
                            key={s.name}
                            className="grid grid-cols-[minmax(0,8rem)_minmax(0,1fr)_3rem] items-center gap-2 text-xs"
                        >
                            <span className="truncate">
                                {s.name}
                                {lowest?.name === s.name && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · lowest
                                    </span>
                                )}
                            </span>
                            <span className="h-2 overflow-hidden rounded-full bg-muted">
                                <span
                                    className={cn(
                                        'block h-full rounded-full',
                                        lowest?.name === s.name
                                            ? 'bg-amber-500'
                                            : 'bg-foreground/45',
                                    )}
                                    style={{ width: `${s.percent}%` }}
                                />
                            </span>
                            <span className="text-right font-medium tabular-nums">
                                {Math.round(s.percent)}%
                            </span>
                        </div>
                    ))}
                </div>
            )}
        </Panel>
    );
}

export function CareerPanel({
    employment,
    promotions,
}: {
    employment: PredictionContext['employment'];
    promotions: PredictionContext['promotions'];
}) {
    return (
        <Panel title="Employment">
            <Facts
                rows={[
                    [
                        'Type',
                        employment.employment_type
                            ? (EMPLOYMENT_TYPES[employment.employment_type] ??
                              employment.employment_type)
                            : '—',
                    ],
                    [
                        'Hired',
                        employment.hired_on
                            ? `${shortDate(employment.hired_on)} · ${formatTenure(employment.tenure_years)}`
                            : '—',
                    ],
                    ['Reports to', employment.manager ?? '—'],
                ]}
            />
            {promotions !== null && (
                <div className="flex flex-col gap-1 border-t border-border pt-2.5">
                    <span className="text-xs text-muted-foreground">
                        Promotions here
                    </span>
                    {promotions.length === 0 ? (
                        <Quiet>None on record.</Quiet>
                    ) : (
                        <ol className="flex flex-col gap-1">
                            {promotions.map((p, i) => (
                                <li
                                    key={`${p.effective_date}-${i}`}
                                    className="flex items-baseline justify-between gap-3 text-sm"
                                >
                                    <span className="truncate">
                                        {p.from && p.to
                                            ? `${p.from} → ${p.to}`
                                            : (p.to ?? 'Promotion')}
                                    </span>
                                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                        {shortDate(p.effective_date)}
                                    </span>
                                </li>
                            ))}
                        </ol>
                    )}
                </div>
            )}
        </Panel>
    );
}

export function LeavePanel({
    leave,
}: {
    leave: NonNullable<PredictionContext['leave']>;
}) {
    return (
        <Panel
            title="Leave, last 12 months"
            icon={Plane}
            aside={`${leave.days_12m} ${leave.days_12m === 1 ? 'day' : 'days'} taken`}
        >
            {leave.by_type.length === 0 ? (
                <Quiet>No approved leave taken.</Quiet>
            ) : (
                <Facts
                    rows={leave.by_type.map((t) => [
                        t.type,
                        `${t.days} ${t.days === 1 ? 'day' : 'days'}`,
                    ])}
                />
            )}
            {(leave.pending > 0 || leave.upcoming.length > 0) && (
                <div className="flex flex-col gap-1 border-t border-border pt-2.5 text-xs text-muted-foreground">
                    {leave.pending > 0 && (
                        <span>
                            {leave.pending}{' '}
                            {leave.pending === 1 ? 'request' : 'requests'}{' '}
                            waiting for a decision
                        </span>
                    )}
                    {leave.upcoming.map((u) => (
                        <span key={`${u.start}-${u.type}`}>
                            Booked: {u.type}, {shortDate(u.start)}
                            {u.end !== u.start ? `–${shortDate(u.end)}` : ''}
                        </span>
                    ))}
                </div>
            )}
        </Panel>
    );
}

export function TrainingPanel({
    training,
}: {
    training: NonNullable<PredictionContext['training']>;
}) {
    return (
        <Panel
            title="Training"
            icon={GraduationCap}
            aside={`${training.completed_12m} completed this year`}
        >
            {training.recent.length === 0 && training.current.length === 0 ? (
                <Quiet>No training in the last year.</Quiet>
            ) : (
                <ul className="flex flex-col gap-1 text-sm">
                    {training.current.map((t) => (
                        <li
                            key={`c-${t.program}`}
                            className="flex items-baseline justify-between gap-3"
                        >
                            <span className="truncate">{t.program}</span>
                            <span className="shrink-0 text-xs text-muted-foreground">
                                {t.status === 'requested'
                                    ? 'Requested'
                                    : 'Enrolled'}
                            </span>
                        </li>
                    ))}
                    {training.recent.map((t) => (
                        <li
                            key={`r-${t.program}-${t.completed_at}`}
                            className="flex items-baseline justify-between gap-3"
                        >
                            <span className="truncate">{t.program}</span>
                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                Completed {shortDate(t.completed_at)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

export function AwardsPanel({
    awards,
}: {
    awards: NonNullable<PredictionContext['awards']>;
}) {
    return (
        <Panel title="Recognition, last 2 years" icon={Award}>
            {awards.length === 0 ? (
                <Quiet>No awards on record.</Quiet>
            ) : (
                <ul className="flex flex-col gap-1 text-sm">
                    {awards.map((a, i) => (
                        <li
                            key={`${a.type}-${a.awarded_on}-${i}`}
                            className="flex items-baseline justify-between gap-3"
                        >
                            <span className="truncate">{a.type}</span>
                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                {shortDate(a.awarded_on)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </Panel>
    );
}

/**
 * The record around a prediction, as far as the viewer may see it: whichever of
 * these the context carries, laid out two to a row on wide screens.
 */
export function RecordPanels({
    context,
    appraisalsFirst = false,
    withoutAppraisals = false,
}: {
    context: PredictionContext;
    /** Put appraisals before attendance (the appraisal-based surfaces). */
    appraisalsFirst?: boolean;
    /** Leave appraisals out (a tab that already shows them). */
    withoutAppraisals?: boolean;
}) {
    const attendance = context.attendance && (
        <AttendanceMonths key="attendance" months={context.attendance.months} />
    );
    const appraisals = !withoutAppraisals && context.appraisals && (
        <AppraisalPanel key="appraisals" appraisals={context.appraisals} />
    );

    return (
        <div className="grid gap-3 md:grid-cols-2">
            {appraisalsFirst
                ? [appraisals, attendance]
                : [attendance, appraisals]}
            <CareerPanel
                employment={context.employment}
                promotions={context.promotions}
            />
            {context.leave && <LeavePanel leave={context.leave} />}
            {context.training && <TrainingPanel training={context.training} />}
            {context.awards && <AwardsPanel awards={context.awards} />}
        </div>
    );
}
