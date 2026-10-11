import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    CircleCheck,
    DoorOpen,
    FileBadge,
    ListChecks,
    MessageSquareText,
    UserX,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DataTable,
    PageBody,
    PageHeader,
    StatTiles,
    TableCard,
} from '@/components/data-table';
import type { StatTile } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { OffboardingNav } from '@/features/offboarding/components/offboarding-nav';
import { ProgressBar } from '@/features/offboarding/components/progress-bar';
import { ResignDialog } from '@/features/offboarding/components/resign-dialog';
import { StatusPill } from '@/features/offboarding/components/status-pill';
import {
    CLEARANCE_STATUS_LABELS,
    CLEARANCE_STATUS_STYLES,
    formatDay,
    INTERVIEW_STATUS_LABELS,
    relativeDay,
    RESIGNATION_STATUS_LABELS,
    RESIGNATION_STATUS_STYLES,
} from '@/features/offboarding/constants';
import { offboardingRoutes } from '@/features/offboarding/routes';
import type {
    MyExitPageProps,
    MyResignation,
} from '@/features/offboarding/types';
import { cn } from '@/lib/utils';

/**
 * My resignation (ADR 0076): file a resignation and withdraw it while HR has
 * not decided; once there is an exit, follow it — the last day, the clearance
 * item by item with what is flagged, the exit interview — and download the
 * Certificate of Employment when the exit is completed (ADR 0077).
 */
export default function MyResignationPage() {
    const {
        has_employee,
        can_resign,
        notice_days,
        today,
        resignations,
        exit,
        interview,
        nav,
    } = usePage<MyExitPageProps>().props;

    const [resignOpen, setResignOpen] = useState(false);
    const [withdrawing, setWithdrawing] = useState<MyResignation | null>(null);
    const [processing, setProcessing] = useState(false);

    const pending = resignations.find((r) => r.status === 'pending') ?? null;

    const withdraw = () => {
        if (!withdrawing) {
            return;
        }

        router.patch(
            offboardingRoutes.withdraw(withdrawing.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => {
                    setProcessing(false);
                    setWithdrawing(null);
                },
            },
        );
    };

    const description = exit
        ? exit.status === 'completed'
            ? `Your last day was ${formatDay(exit.last_working_day)}.`
            : exit.last_working_day
              ? `Your last working day is ${formatDay(exit.last_working_day)}, ${relativeDay(today, exit.last_working_day)}.`
              : 'HR has started your exit.'
        : pending
          ? 'Your resignation is waiting for HR.'
          : 'If you decide to leave, tell HR here. Nothing changes until they accept it.';

    return (
        <>
            <Head title="My resignation" />

            <PageBody>
                <PageHeader
                    title="My resignation"
                    description={description}
                    actions={<OffboardingNav current="me" counts={nav} />}
                />

                {!has_employee ? (
                    <Empty
                        icon={<UserX className="size-8" />}
                        title="Your account isn’t linked to an employee"
                        body="Resigning belongs to an employee record. Ask HR to link your account to yours."
                    />
                ) : (
                    <>
                        {exit && (
                            <ExitSummary
                                exit={exit}
                                interview={interview}
                                today={today}
                            />
                        )}

                        {pending && (
                            <section className="flex flex-col gap-3 rounded-xl border border-amber-500/30 bg-amber-500/[0.06] p-4 sm:flex-row sm:items-center sm:justify-between">
                                <div className="min-w-0">
                                    <p className="text-sm font-semibold">
                                        Your resignation is with HR
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Filed {formatDay(pending.filed_on)}, for
                                        a last day of{' '}
                                        <span className="font-medium text-foreground">
                                            {formatDay(
                                                pending.proposed_last_day,
                                            )}
                                        </span>{' '}
                                        ({pending.notice_days} days’ notice). HR
                                        will agree the date with you.
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="shrink-0"
                                    onClick={() => setWithdrawing(pending)}
                                >
                                    Withdraw it
                                </Button>
                            </section>
                        )}

                        {can_resign && (
                            <section className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 sm:flex-row sm:items-center sm:justify-between dark:border-sidebar-border">
                                <div className="flex min-w-0 items-start gap-3">
                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0a8b91] dark:text-[#0ABFBF]">
                                        <DoorOpen className="size-4" />
                                    </span>
                                    <div className="min-w-0">
                                        <p className="text-sm font-semibold">
                                            Thinking of leaving?
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Propose your last day and HR takes
                                            it from there. The Labor Code asks
                                            for {notice_days} days’ notice.
                                        </p>
                                    </div>
                                </div>
                                <Button
                                    size="sm"
                                    className="shrink-0"
                                    onClick={() => setResignOpen(true)}
                                >
                                    File your resignation
                                </Button>
                            </section>
                        )}

                        {resignations.length > 0 && (
                            <TableCard
                                title="Your resignations"
                                count={resignations.length}
                            >
                                <DataTable>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Filed</TableHead>
                                            <TableHead>
                                                Last day proposed
                                            </TableHead>
                                            <TableHead className="text-right">
                                                Notice
                                            </TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>HR’s note</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {resignations.map((resignation) => (
                                            <TableRow key={resignation.id}>
                                                <TableCell className="whitespace-nowrap tabular-nums">
                                                    {formatDay(
                                                        resignation.filed_on,
                                                    )}
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap tabular-nums">
                                                    {formatDay(
                                                        resignation.proposed_last_day,
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {resignation.notice_days}{' '}
                                                    days
                                                </TableCell>
                                                <TableCell>
                                                    <StatusPill
                                                        label={
                                                            RESIGNATION_STATUS_LABELS[
                                                                resignation
                                                                    .status
                                                            ]
                                                        }
                                                        className={
                                                            RESIGNATION_STATUS_STYLES[
                                                                resignation
                                                                    .status
                                                            ]
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="max-w-80 text-sm whitespace-normal text-muted-foreground">
                                                    {resignation.decision_note ??
                                                        '—'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </DataTable>
                            </TableCard>
                        )}

                        {!exit && !pending && !can_resign && (
                            <Empty
                                icon={<CircleCheck className="size-8" />}
                                title="Nothing to do here"
                                body="Your employment has ended. If you need your records, ask HR."
                            />
                        )}
                    </>
                )}
            </PageBody>

            <ResignDialog
                open={resignOpen}
                onOpenChange={setResignOpen}
                today={today}
                noticeDays={notice_days}
            />

            <ConfirmDialog
                open={withdrawing !== null}
                onOpenChange={(open) => !open && setWithdrawing(null)}
                title="Withdraw your resignation?"
                description="HR stops reviewing it. You can file a new one later."
                confirmLabel="Withdraw it"
                processing={processing}
                onConfirm={withdraw}
            />
        </>
    );
}

/** The exit once there is one: the dates, the clearance, the survey, the certificate. */
function ExitSummary({
    exit,
    interview,
    today,
}: {
    exit: NonNullable<MyExitPageProps['exit']>;
    interview: MyExitPageProps['interview'];
    today: string;
}) {
    const { clearance } = exit;
    const percent =
        clearance.total === 0
            ? 0
            : Math.round((clearance.cleared / clearance.total) * 100);

    const tiles: StatTile[] = [
        {
            key: 'last-day',
            label: 'Last working day',
            value: formatDay(exit.last_working_day),
            hint:
                exit.last_working_day && exit.status !== 'completed'
                    ? relativeDay(today, exit.last_working_day)
                    : undefined,
            icon: CalendarClock,
            accent: 'teal',
        },
        {
            key: 'clearance',
            label: 'Clearance',
            value: `${clearance.cleared} of ${clearance.total}`,
            hint:
                clearance.flagged > 0
                    ? `${clearance.flagged} flagged`
                    : 'signed off',
            icon: ListChecks,
            accent: clearance.flagged > 0 ? 'rose' : 'emerald',
        },
        {
            key: 'interview',
            label: 'Exit interview',
            value: interview ? INTERVIEW_STATUS_LABELS[interview.status] : '—',
            icon: MessageSquareText,
            accent: interview?.can_answer ? 'amber' : 'slate',
        },
        {
            key: 'certificate',
            label: 'Certificate of Employment',
            value: exit.certificate.ready ? 'Ready' : 'Not yet',
            hint: exit.certificate.ready
                ? undefined
                : 'once your exit is completed',
            icon: FileBadge,
            accent: exit.certificate.ready ? 'emerald' : 'slate',
        },
    ];

    return (
        <>
            <StatTiles tiles={tiles} />

            {(interview?.can_answer || exit.certificate.ready) && (
                <div className="flex flex-wrap items-center gap-2">
                    {interview?.can_answer && (
                        <Button size="sm" asChild>
                            <Link href={offboardingRoutes.myInterview}>
                                <MessageSquareText className="size-4" />
                                Answer the exit interview
                            </Link>
                        </Button>
                    )}
                    {exit.certificate.ready && (
                        <Button size="sm" variant="outline" asChild>
                            <a
                                href={offboardingRoutes.myCertificate}
                                target="_blank"
                                rel="noopener"
                            >
                                <FileBadge className="size-4" />
                                Download your Certificate of Employment
                            </a>
                        </Button>
                    )}
                </div>
            )}

            <TableCard
                title="Your clearance"
                description="What each department signs off before you go. A flagged item says what is still needed from you."
                actions={
                    <div className="flex w-40 items-center gap-2">
                        <ProgressBar percent={percent} />
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {percent}%
                        </span>
                    </div>
                }
            >
                <DataTable>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Item</TableHead>
                            <TableHead className="hidden sm:table-cell">
                                Department
                            </TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead className="hidden sm:table-cell">
                                Signed off
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {exit.items.map((item) => (
                            <TableRow key={item.id}>
                                <TableCell className="max-w-96 whitespace-normal">
                                    <p
                                        className={cn(
                                            'text-sm',
                                            item.status === 'cleared' &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        {item.item}
                                    </p>
                                    {/* On a phone the department rides under the item. */}
                                    <p className="text-xs text-muted-foreground sm:hidden">
                                        {item.department ?? 'Unassigned'}
                                        {item.cleared_on &&
                                            ` · signed off ${formatDay(item.cleared_on)}`}
                                    </p>
                                    {item.remarks && (
                                        <p className="text-xs text-rose-600 dark:text-rose-400">
                                            {item.remarks}
                                        </p>
                                    )}
                                </TableCell>
                                <TableCell className="hidden text-sm text-muted-foreground sm:table-cell">
                                    {item.department ?? 'Unassigned'}
                                </TableCell>
                                <TableCell>
                                    <StatusPill
                                        label={
                                            CLEARANCE_STATUS_LABELS[item.status]
                                        }
                                        className={
                                            CLEARANCE_STATUS_STYLES[item.status]
                                        }
                                    />
                                </TableCell>
                                <TableCell className="hidden text-sm whitespace-nowrap text-muted-foreground tabular-nums sm:table-cell">
                                    {item.cleared_on
                                        ? formatDay(item.cleared_on)
                                        : '—'}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </DataTable>
            </TableCard>
        </>
    );
}

function Empty({
    icon,
    title,
    body,
}: {
    icon: ReactNode;
    title: string;
    body: string;
}) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-14 text-center dark:border-sidebar-border">
            <span className="text-muted-foreground">{icon}</span>
            <p className="text-sm font-medium">{title}</p>
            <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
        </div>
    );
}

MyResignationPage.layout = {
    breadcrumbs: [
        { title: 'Offboarding', href: offboardingRoutes.index },
        { title: 'My resignation', href: offboardingRoutes.me },
    ],
};
