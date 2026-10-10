import { Head, Link, usePage } from '@inertiajs/react';
import {
    BadgeCheck,
    CalendarPlus,
    CalendarX2,
    Clock,
    LibraryBig,
    MapPin,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageBody, PageHeader } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { SubscribeButton } from '@/features/events/components/subscribe-dialog';
import { withdrawEnrollment } from '@/features/training/api';
import { TrainingNav } from '@/features/training/components/training-nav';
import {
    CertificateStandingBadge,
    EnrollmentStatusBadge,
} from '@/features/training/components/training-status-badge';
import {
    formatDate,
    formatDateRange,
    formatScore,
    formatSessionTime,
    scoreTone,
    todayIso,
} from '@/features/training/constants';
import { trainingRoutes } from '@/features/training/routes';
import type {
    MyEnrollment,
    MySession,
    MyTrainingPageProps,
    MyTrainingTab,
    TrainingCertificate,
} from '@/features/training/types';
import { usePermissions } from '@/hooks/use-permissions';
import { cn } from '@/lib/utils';

const TAB_LABELS: Record<MyTrainingTab, string> = {
    sessions: 'Coming up',
    programs: 'Programs',
    certificates: 'Certificates',
};

/** Sessions grouped by their day, in the order given. */
function byDay(sessions: MySession[]): { day: string; items: MySession[] }[] {
    const groups: { day: string; items: MySession[] }[] = [];

    for (const session of sessions) {
        const last = groups.at(-1);

        if (last && last.day === session.session_date) {
            last.items.push(session);
        } else {
            groups.push({ day: session.session_date, items: [session] });
        }
    }

    return groups;
}

/**
 * My training (ADR 0074): the sessions coming up on the programs the person
 * holds a seat on, as an agenda; their programs with how each is going —
 * attendance, score, a request waiting — and the certificates they have earned
 * with when each expires (ADR 0075).
 */
export default function MyTraining() {
    const { enrollments, sessions, certificates, tab, has_employee, nav } =
        usePage<MyTrainingPageProps>().props;
    const { can } = usePermissions();

    const [view, setView] = useState<MyTrainingTab>(
        tab ?? (sessions.length > 0 ? 'sessions' : 'programs'),
    );
    const [leaving, setLeaving] = useState<MyEnrollment | null>(null);
    const [processing, setProcessing] = useState(false);

    const counts: Record<MyTrainingTab, number> = {
        sessions: sessions.length,
        programs: enrollments.length,
        certificates: certificates.length,
    };
    const waiting = enrollments.filter((e) => e.status === 'requested').length;
    const next = sessions[0];

    const leave = () => {
        if (!leaving) {
            return;
        }

        withdrawEnrollment(leaving.id, {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setLeaving(null);
            },
        });
    };

    return (
        <>
            <Head title="My training" />

            <PageBody>
                <PageHeader
                    title="My training"
                    description={
                        next
                            ? `Next: ${next.program.name ?? 'a session'}, ${formatDate(next.session_date)} at ${formatSessionTime(next.starts_at, next.ends_at).split(' – ')[0]}.`
                            : waiting > 0
                              ? `${waiting} ${waiting === 1 ? 'request is' : 'requests are'} waiting for HR.`
                              : 'Your programs, your sessions and the certificates you’ve earned.'
                    }
                    actions={<TrainingNav current="me" counts={nav} />}
                />

                {!has_employee ? (
                    <Empty
                        icon={<CalendarX2 className="size-8" />}
                        title="Your account isn’t linked to an employee"
                        body="Training belongs to employees. Ask HR to link your account to your employee record."
                    />
                ) : (
                    <>
                        <div className="flex flex-col gap-2 border-b border-border sm:flex-row sm:items-end sm:justify-between">
                            <div
                                role="tablist"
                                aria-label="My training"
                                className="flex items-center gap-1 overflow-x-auto"
                            >
                                {(
                                    Object.keys(TAB_LABELS) as MyTrainingTab[]
                                ).map((option) => (
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
                                        {TAB_LABELS[option]}
                                        <span className="ml-1.5 text-muted-foreground tabular-nums">
                                            {counts[option]}
                                        </span>
                                    </button>
                                ))}
                            </div>
                            {view === 'sessions' && can('events.respond') && (
                                <div className="pb-2">
                                    <SubscribeButton />
                                </div>
                            )}
                        </div>

                        {view === 'sessions' && <Agenda sessions={sessions} />}

                        {view === 'programs' &&
                            (enrollments.length === 0 ? (
                                <BrowseEmpty />
                            ) : (
                                <ul className="grid gap-3 lg:grid-cols-2">
                                    {enrollments.map((enrollment) => (
                                        <ProgramCard
                                            key={enrollment.id}
                                            enrollment={enrollment}
                                            onLeave={() =>
                                                setLeaving(enrollment)
                                            }
                                        />
                                    ))}
                                </ul>
                            ))}

                        {view === 'certificates' &&
                            (certificates.length === 0 ? (
                                <Empty
                                    icon={<BadgeCheck className="size-8" />}
                                    title="No certificates yet"
                                    body="Finishing a program that awards a certificate adds it here, and to your profile."
                                />
                            ) : (
                                <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                                    {certificates.map((certificate) => (
                                        <CertificateCard
                                            key={certificate.id}
                                            certificate={certificate}
                                        />
                                    ))}
                                </ul>
                            ))}
                    </>
                )}
            </PageBody>

            <ConfirmDialog
                open={leaving !== null}
                onOpenChange={(open) => !open && setLeaving(null)}
                title={
                    leaving?.status === 'requested'
                        ? 'Withdraw your request?'
                        : 'Leave this program?'
                }
                description={
                    leaving?.status === 'requested'
                        ? `Your request to join ${leaving.program.name} is withdrawn.`
                        : `You give up your seat on ${leaving?.program.name ?? 'the program'}, and its sessions leave your calendar.`
                }
                confirmLabel={
                    leaving?.status === 'requested' ? 'Withdraw' : 'Leave'
                }
                destructive
                processing={processing}
                onConfirm={leave}
            />
        </>
    );
}

function Agenda({ sessions }: { sessions: MySession[] }) {
    if (sessions.length === 0) {
        return (
            <Empty
                icon={<CalendarX2 className="size-8" />}
                title="Nothing coming up"
                body="When a program you’re on schedules a session, it lands here."
            />
        );
    }

    return (
        <ol className="flex flex-col gap-6">
            {byDay(sessions).map((group) => (
                <li
                    key={group.day}
                    className="grid grid-cols-1 gap-3 sm:grid-cols-[4.5rem_1fr] sm:gap-5"
                >
                    <DateRail iso={group.day} />
                    <ul className="flex flex-col gap-3">
                        {group.items.map((session) => (
                            <li
                                key={session.id}
                                className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 lg:flex-row lg:items-start lg:justify-between dark:border-sidebar-border"
                            >
                                <div className="flex min-w-0 flex-col gap-1.5">
                                    <h2 className="text-base font-semibold">
                                        {session.program.name}
                                    </h2>
                                    {session.title && (
                                        <p className="text-sm">
                                            {session.title}
                                        </p>
                                    )}
                                    <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                                        <li className="inline-flex items-center gap-1 tabular-nums">
                                            <Clock className="size-3.5" />
                                            {formatSessionTime(
                                                session.starts_at,
                                                session.ends_at,
                                            )}
                                        </li>
                                        {session.location && (
                                            <li className="inline-flex items-center gap-1">
                                                <MapPin className="size-3.5" />
                                                {session.location}
                                            </li>
                                        )}
                                    </ul>
                                    {session.notes && (
                                        <p className="max-w-2xl text-sm text-muted-foreground">
                                            {session.notes}
                                        </p>
                                    )}
                                </div>
                                <a
                                    href={trainingRoutes.sessionIcs(session.id)}
                                    className="inline-flex shrink-0 items-center gap-1 self-start text-xs text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                                >
                                    <CalendarPlus className="size-3.5" />
                                    Add to calendar
                                </a>
                            </li>
                        ))}
                    </ul>
                </li>
            ))}
        </ol>
    );
}

/** The day, set large: the rail the agenda hangs on. */
function DateRail({ iso }: { iso: string }) {
    const day = new Date(`${iso}T00:00:00`);
    const isToday = iso === todayIso();

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

function ProgramCard({
    enrollment,
    onLeave,
}: {
    enrollment: MyEnrollment;
    onLeave: () => void;
}) {
    const { program, attendance } = enrollment;

    return (
        <li className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border">
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2 className="font-semibold">{program.name}</h2>
                    <p className="text-xs text-muted-foreground">
                        {program.provider ?? 'In-house'} ·{' '}
                        {formatDateRange(program.start_date, program.end_date)}
                    </p>
                </div>
                <EnrollmentStatusBadge status={enrollment.status} />
            </div>

            {enrollment.status === 'requested' && (
                <p className="text-sm text-muted-foreground">
                    You asked to join on {formatDate(enrollment.requested_on)}.
                    HR will decide, and you’ll be told.
                </p>
            )}

            {enrollment.status === 'declined' && (
                <p className="text-sm text-muted-foreground">
                    HR declined your request.
                    {enrollment.decision_note && (
                        <span className="mt-1 block rounded-md bg-muted/60 px-3 py-2 text-foreground">
                            {enrollment.decision_note}
                        </span>
                    )}
                </p>
            )}

            {(enrollment.status === 'enrolled' ||
                enrollment.status === 'completed' ||
                enrollment.status === 'dropped') && (
                <dl className="grid grid-cols-3 gap-3 text-sm">
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Attendance
                        </dt>
                        <dd className="font-semibold tabular-nums">
                            {attendance.rate === null
                                ? '—'
                                : `${attendance.rate}%`}
                            {attendance.counted > 0 && (
                                <span className="ml-1 text-xs font-normal text-muted-foreground">
                                    {attendance.attended}/{attendance.counted}
                                </span>
                            )}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">Score</dt>
                        <dd
                            className={cn(
                                'font-semibold tabular-nums',
                                scoreTone(enrollment.score),
                            )}
                        >
                            {formatScore(enrollment.score)}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Completed
                        </dt>
                        <dd className="font-semibold">
                            {enrollment.completed_at
                                ? formatDate(enrollment.completed_at)
                                : '—'}
                        </dd>
                    </div>
                </dl>
            )}

            {(enrollment.certificate_id !== null ||
                enrollment.can_withdraw) && (
                <div className="flex flex-wrap items-center justify-between gap-2 border-t border-border pt-3">
                    {enrollment.certificate_id !== null ? (
                        <Link
                            href={trainingRoutes.certificate(
                                enrollment.certificate_id,
                            )}
                            className="inline-flex items-center gap-1.5 text-sm font-medium text-[#08767c] underline-offset-4 hover:underline dark:text-[#0ABFBF]"
                        >
                            <BadgeCheck className="size-4" />
                            View certificate
                        </Link>
                    ) : (
                        <span />
                    )}
                    {enrollment.can_withdraw && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground hover:text-destructive"
                            onClick={onLeave}
                        >
                            {enrollment.status === 'requested'
                                ? 'Withdraw request'
                                : 'Leave program'}
                        </Button>
                    )}
                </div>
            )}
        </li>
    );
}

function CertificateCard({
    certificate,
}: {
    certificate: TrainingCertificate;
}) {
    return (
        <li>
            <Link
                href={trainingRoutes.certificate(certificate.id)}
                className="flex h-full flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 transition-colors hover:border-[#0ABFBF]/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:border-sidebar-border"
            >
                <div className="flex items-start justify-between gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                        <BadgeCheck className="size-5" />
                    </span>
                    <CertificateStandingBadge standing={certificate.standing} />
                </div>
                <div className="min-w-0">
                    <p className="font-semibold">{certificate.name}</p>
                    <p className="text-xs text-muted-foreground">
                        {certificate.certificate_no}
                        {certificate.issuer && ` · ${certificate.issuer}`}
                    </p>
                </div>
                <p className="mt-auto text-xs text-muted-foreground">
                    Issued {formatDate(certificate.issued_date)} ·{' '}
                    {certificate.expiry_date
                        ? `${certificate.standing === 'expired' ? 'expired' : 'expires'} ${formatDate(certificate.expiry_date)}`
                        : 'no expiry'}
                </p>
            </Link>
        </li>
    );
}

function BrowseEmpty() {
    return (
        <Empty
            icon={<LibraryBig className="size-8" />}
            title="You’re not on any training yet"
            body="Programs you can join yourself are under Browse. HR may also enroll you — you’re told when they do."
            action={
                <Button size="sm" variant="outline" asChild>
                    <Link href={trainingRoutes.catalog}>Browse programs</Link>
                </Button>
            }
        />
    );
}

function Empty({
    icon,
    title,
    body,
    action,
}: {
    icon: ReactNode;
    title: string;
    body: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-14 text-center">
            <span className="text-muted-foreground" aria-hidden>
                {icon}
            </span>
            <p className="font-medium">{title}</p>
            <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
            {action && <div className="mt-2">{action}</div>}
        </div>
    );
}

MyTraining.layout = {
    breadcrumbs: [
        { title: 'Training & Development', href: '/training' },
        { title: 'My training', href: '/training/me' },
    ],
};
