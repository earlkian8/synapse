import { Head, router, usePage } from '@inertiajs/react';
import {
    Hourglass,
    MessageSquareText,
    Send,
    Star,
    ThumbsUp,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    DataTable,
    EmptyTableRow,
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    rowOpens,
    StatTiles,
    TableCard,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { OffboardingNav } from '@/features/offboarding/components/offboarding-nav';
import { StatusPill } from '@/features/offboarding/components/status-pill';
import {
    formatDay,
    INTERVIEW_STATUS_LABELS,
    INTERVIEW_STATUS_STYLES,
    TYPE_LABELS,
    YES_MAYBE_NO_LABELS,
} from '@/features/offboarding/constants';
import { offboardingRoutes } from '@/features/offboarding/routes';
import type {
    InterviewPeriod,
    InterviewsPageProps,
} from '@/features/offboarding/types';

const PERIODS: { value: InterviewPeriod; label: string }[] = [
    { value: '12m', label: 'Last 12 months' },
    { value: '6m', label: 'Last 6 months' },
    { value: '3m', label: 'Last 3 months' },
    { value: 'all', label: 'All time' },
];

const STATUS_FILTERS = [
    { value: 'all', label: 'All surveys' },
    { value: 'submitted', label: 'Answered' },
    { value: 'pending', label: 'Waiting for answers' },
    { value: 'waived', label: 'Waived' },
];

/** The one bar colour: the brand teal stepped for contrast on either surface. */
const BAR = 'bg-[#08959b] dark:bg-[#0aa7ad]';

/** A share as a whole percentage. */
function percent(value: number): string {
    return `${Math.round(value * 100)}%`;
}

/**
 * Exit interviews (ADR 0076): what leavers said, read together over a period —
 * why people leave and how they rate their time here, each a table with its own
 * bar — and every survey of the period below, answered or not. A row opens the
 * exit, on its interview.
 */
export default function ExitInterviewsPage() {
    const { period, summary, interviews, nav } =
        usePage<InterviewsPageProps>().props;
    const [status, setStatus] = useState('all');

    const rows = useMemo(
        () =>
            interviews.filter(
                (row) => status === 'all' || row.status === status,
            ),
        [interviews, status],
    );
    const page = useClientPagination(rows, `${period}|${status}`);

    const recommendTotal =
        summary.recommend.yes + summary.recommend.maybe + summary.recommend.no;
    const returnTotal =
        summary.return.yes + summary.return.maybe + summary.return.no;
    const topReason = summary.reasons[0]?.share ?? 0;

    return (
        <>
            <Head title="Exit interviews" />

            <PageBody>
                <PageHeader
                    title="Exit interviews"
                    description="Why people leave, and how they rate their time here — from the surveys sent with every exit."
                    actions={
                        <OffboardingNav current="interviews" counts={nav} />
                    }
                />

                <StatTiles
                    tiles={[
                        {
                            key: 'sent',
                            label: 'Sent',
                            value: summary.sent,
                            hint:
                                summary.waived > 0
                                    ? `${summary.waived} waived`
                                    : undefined,
                            icon: Send,
                            accent: 'sky',
                        },
                        {
                            key: 'answered',
                            label: 'Answered',
                            value: summary.responses,
                            hint:
                                summary.response_rate !== null
                                    ? `${percent(summary.response_rate)} of those asked`
                                    : undefined,
                            icon: MessageSquareText,
                            accent: 'teal',
                        },
                        {
                            key: 'waiting',
                            label: 'Waiting',
                            value: summary.waiting,
                            icon: Hourglass,
                            accent: summary.waiting > 0 ? 'amber' : 'slate',
                        },
                        {
                            key: 'average',
                            label: 'Average rating',
                            value:
                                summary.average !== null
                                    ? summary.average.toFixed(1)
                                    : '—',
                            hint: summary.average !== null ? 'of 5' : undefined,
                            icon: Star,
                            accent: 'violet',
                        },
                        {
                            key: 'recommend',
                            label: 'Would recommend us',
                            value:
                                recommendTotal > 0
                                    ? percent(
                                          summary.recommend.yes /
                                              recommendTotal,
                                      )
                                    : '—',
                            hint:
                                returnTotal > 0
                                    ? `${percent(summary.return.yes / returnTotal)} would return`
                                    : undefined,
                            icon: ThumbsUp,
                            accent: 'emerald',
                        },
                    ]}
                />

                <ListToolbar
                    filtered={period !== '12m'}
                    onReset={() =>
                        router.get(
                            offboardingRoutes.interviews,
                            {},
                            { preserveScroll: true },
                        )
                    }
                >
                    <FilterSelect
                        label="Period"
                        value={period}
                        onChange={(value) =>
                            router.get(
                                offboardingRoutes.interviews,
                                value === '12m' ? {} : { period: value },
                                { preserveScroll: true, preserveState: true },
                            )
                        }
                        options={PERIODS}
                        className="w-40"
                    />
                </ListToolbar>

                <div className="grid gap-4 lg:grid-cols-2">
                    <TableCard
                        title="Why people leave"
                        description="The main reason each leaver gave."
                    >
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Reason</TableHead>
                                    <TableHead className="text-right">
                                        Leavers
                                    </TableHead>
                                    <TableHead className="w-[40%]">
                                        Share
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {summary.reasons.length === 0 && (
                                    <EmptyTableRow
                                        colSpan={3}
                                        icon={MessageSquareText}
                                        title="No answers yet"
                                        description="Reasons appear here as leavers answer their exit interview."
                                    />
                                )}
                                {summary.reasons.map((reason) => (
                                    <TableRow
                                        key={reason.key}
                                        title={`${reason.label}: ${reason.count} of ${summary.responses} (${percent(reason.share)})`}
                                    >
                                        <TableCell className="text-sm">
                                            {reason.label}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {reason.count}
                                        </TableCell>
                                        <TableCell>
                                            <Meter
                                                value={reason.share}
                                                max={topReason}
                                                label={percent(reason.share)}
                                            />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </DataTable>
                    </TableCard>

                    <TableCard
                        title="How leavers rate us"
                        description="1 is very poor, 5 very good. The counts are how many gave each score."
                    >
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Topic</TableHead>
                                    <TableHead className="w-[30%]">
                                        Average
                                    </TableHead>
                                    {[1, 2, 3, 4, 5].map((score) => (
                                        <TableHead
                                            key={score}
                                            className="w-9 text-right tabular-nums"
                                        >
                                            {score}
                                        </TableHead>
                                    ))}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {summary.ratings.map((rating) => (
                                    <TableRow
                                        key={rating.key}
                                        title={
                                            rating.average !== null
                                                ? `${rating.label}: ${rating.average.toFixed(1)} of 5 from ${rating.count} answers`
                                                : `${rating.label}: no answers yet`
                                        }
                                    >
                                        <TableCell className="text-sm">
                                            {rating.label}
                                        </TableCell>
                                        <TableCell>
                                            {rating.average !== null ? (
                                                <Meter
                                                    value={rating.average}
                                                    max={5}
                                                    label={rating.average.toFixed(
                                                        1,
                                                    )}
                                                />
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                        {[1, 2, 3, 4, 5].map((score) => (
                                            <TableCell
                                                key={score}
                                                className="text-right text-muted-foreground tabular-nums"
                                            >
                                                {rating.distribution[score] ||
                                                    '·'}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </DataTable>
                    </TableCard>
                </div>

                <div className="flex flex-col gap-3">
                    <ListToolbar
                        filtered={status !== 'all'}
                        onReset={() => setStatus('all')}
                    >
                        <FilterSelect
                            label="Filter by status"
                            value={status}
                            onChange={setStatus}
                            options={STATUS_FILTERS}
                            className="w-48"
                        />
                    </ListToolbar>

                    <TableCard title="Surveys" count={rows.length}>
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead>Exit</TableHead>
                                    <TableHead>Sent</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Main reason</TableHead>
                                    <TableHead className="text-right">
                                        Avg.
                                    </TableHead>
                                    <TableHead>Recommend</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {page.rows.length === 0 && (
                                    <EmptyTableRow
                                        colSpan={7}
                                        icon={MessageSquareText}
                                        title="No surveys in this period"
                                        description="A survey goes out with every exit except a termination. Send one from an exit’s Exit interview tab."
                                    />
                                )}
                                {page.rows.map((row) => (
                                    <TableRow
                                        key={row.id}
                                        {...rowOpens(
                                            `${offboardingRoutes.show(row.case_id)}?tab=interview`,
                                        )}
                                    >
                                        <TableCell>
                                            <div className="flex items-center gap-2.5">
                                                <PersonAvatar
                                                    name={
                                                        row.employee.full_name
                                                    }
                                                    initials={
                                                        row.employee.initials
                                                    }
                                                    photo={row.employee.photo}
                                                    className="size-8"
                                                />
                                                <div className="min-w-0">
                                                    <p className="truncate font-medium">
                                                        {row.employee.full_name}
                                                    </p>
                                                    <p className="truncate text-xs text-muted-foreground">
                                                        {row.employee
                                                            .department ??
                                                            row.employee
                                                                .employee_no}
                                                    </p>
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-sm whitespace-nowrap">
                                            {TYPE_LABELS[row.exit_type]}
                                            <span className="block text-xs text-muted-foreground">
                                                Last day{' '}
                                                {formatDay(
                                                    row.last_working_day,
                                                )}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-sm whitespace-nowrap tabular-nums">
                                            {formatDay(row.sent_on)}
                                        </TableCell>
                                        <TableCell>
                                            <StatusPill
                                                label={
                                                    INTERVIEW_STATUS_LABELS[
                                                        row.status
                                                    ]
                                                }
                                                className={
                                                    INTERVIEW_STATUS_STYLES[
                                                        row.status
                                                    ]
                                                }
                                            />
                                        </TableCell>
                                        <TableCell className="max-w-56 truncate text-sm">
                                            {row.reason ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {row.average?.toFixed(1) ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-sm">
                                            {row.recommend
                                                ? YES_MAYBE_NO_LABELS[
                                                      row.recommend
                                                  ]
                                                : '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </DataTable>
                    </TableCard>

                    <TablePagination
                        meta={page.meta}
                        perPage={page.perPage}
                        onPage={page.setPage}
                        onPerPage={page.setPerPage}
                    />
                </div>
            </PageBody>
        </>
    );
}

/**
 * One value as a thin bar on a muted track, its number beside it in text ink —
 * a single series, so the column heading names it and no legend is needed.
 */
function Meter({
    value,
    max,
    label,
}: {
    value: number;
    max: number;
    label: string;
}) {
    const width = max > 0 ? Math.max(2, (value / max) * 100) : 0;

    return (
        <div className="flex items-center gap-2">
            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                <div
                    className={`h-full rounded-full ${BAR}`}
                    style={{ width: `${width}%` }}
                />
            </div>
            <span className="w-9 shrink-0 text-right text-xs text-muted-foreground tabular-nums">
                {label}
            </span>
        </div>
    );
}

ExitInterviewsPage.layout = {
    breadcrumbs: [
        { title: 'Offboarding', href: offboardingRoutes.index },
        { title: 'Exit interviews', href: offboardingRoutes.interviews },
    ],
};
