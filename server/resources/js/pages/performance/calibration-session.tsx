import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarDays,
    CheckCheck,
    Hourglass,
    Pencil,
    Scale,
    Send,
    Users,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DataTable,
    EmptyTableRow,
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    SearchInput,
    StatTiles,
    TableCard,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cancelSession, completeSession } from '@/features/performance/api';
import { AdjustRatingModal } from '@/features/performance/components/adjust-rating-modal';
import { BandChip } from '@/features/performance/components/band-chip';
import { CalibrationTable } from '@/features/performance/components/calibration-table';
import { SessionFormModal } from '@/features/performance/components/session-form-modal';
import {
    bandTone,
    formatDate,
    formatPercent,
    formatTimestamp,
    PILL,
    SESSION_STATUS,
} from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import type {
    CalibrationRow,
    CalibrationSessionPageProps,
    CalibrationSpread,
} from '@/features/performance/types';
import { cn } from '@/lib/utils';

const VIEWS = [
    { value: 'all', label: 'Everyone in the session' },
    { value: 'ready', label: 'Ready to calibrate' },
    { value: 'moved', label: 'Moved' },
    { value: 'draft', label: 'Not submitted yet' },
];

/**
 * One calibration session (ADR 0073): the appraisals it covers side by side —
 * what each scorecard gave and what it is rated now — with the band spread
 * before and after the session's moves, and each department read against the
 * session's average. Moving a rating takes a reason; completing the session
 * shares the appraisals it was holding with their employees.
 */
export default function CalibrationSessionPage() {
    const { session, board, calibrators, can, me } =
        usePage<CalibrationSessionPageProps>().props;

    const [search, setSearch] = useState('');
    const [view, setView] = useState('all');
    const [department, setDepartment] = useState('all');
    const [moving, setMoving] = useState<CalibrationRow | null>(null);
    const [editing, setEditing] = useState(false);
    const [confirm, setConfirm] = useState<'complete' | 'cancel' | null>(null);
    const [processing, setProcessing] = useState(false);

    const open = session.status === 'open';
    const manage = can.manage && open;

    const departments = useMemo(
        () =>
            [
                ...new Set(
                    board.rows
                        .map((row) => row.employee?.department)
                        .filter((d): d is string => Boolean(d)),
                ),
            ].sort(),
        [board.rows],
    );

    const rows = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return board.rows.filter((row) => {
            if (view === 'ready' && row.status !== 'submitted') {
                return false;
            }

            if (view === 'moved' && !row.calibrated) {
                return false;
            }

            if (view === 'draft' && row.status !== 'draft') {
                return false;
            }

            if (
                department !== 'all' &&
                row.employee?.department !== department
            ) {
                return false;
            }

            return (
                needle === '' ||
                [row.employee?.full_name, row.employee?.position, row.evaluator]
                    .join(' ')
                    .toLowerCase()
                    .includes(needle)
            );
        });
    }, [board.rows, search, view, department]);

    const handlers = {
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirm(null);
        },
    };

    return (
        <>
            <Head title={session.name} />

            <PageBody>
                <PageHeader
                    back={{
                        href: performanceRoutes.calibration(
                            session.period?.id ?? null,
                        ),
                        label: 'Back to calibration',
                    }}
                    title={session.name}
                    badges={
                        <span
                            className={cn(
                                PILL,
                                SESSION_STATUS[session.status].className,
                            )}
                        >
                            {SESSION_STATUS[session.status].label}
                        </span>
                    }
                    description={
                        <span className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs">
                            <span className="font-medium text-foreground">
                                {session.period?.name}
                            </span>
                            <span>{session.scope_label}</span>
                            {session.scheduled_for && (
                                <span className="inline-flex items-center gap-1.5">
                                    <CalendarDays className="size-3.5" />
                                    {formatDate(session.scheduled_for)}
                                </span>
                            )}
                            {session.participants &&
                                session.participants.length > 0 && (
                                    <span className="inline-flex items-center gap-1.5">
                                        <Users className="size-3.5" />
                                        {session.participants
                                            .map((p) => p.name)
                                            .join(', ')}
                                    </span>
                                )}
                            {session.completed_at && (
                                <span>
                                    Completed{' '}
                                    {formatTimestamp(session.completed_at)}
                                </span>
                            )}
                        </span>
                    }
                    actions={
                        manage && (
                            <>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setEditing(true)}
                                >
                                    <Pencil className="size-4" />
                                    Edit
                                </Button>
                                {session.adjustments_count === 0 && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => setConfirm('cancel')}
                                    >
                                        <X className="size-4" />
                                        Cancel session
                                    </Button>
                                )}
                                <Button
                                    size="sm"
                                    onClick={() => setConfirm('complete')}
                                >
                                    <CheckCheck className="size-4" />
                                    Complete
                                </Button>
                            </>
                        )
                    }
                />

                {session.notes && (
                    <p className="rounded-xl border border-dashed border-border bg-muted/30 px-4 py-3 text-sm whitespace-pre-line text-muted-foreground">
                        {session.notes}
                    </p>
                )}

                <StatTiles
                    tiles={[
                        {
                            key: 'total',
                            label: 'In this session',
                            value: board.counts.total.toLocaleString(),
                            hint: 'appraisals',
                            icon: Users,
                            accent: 'teal',
                        },
                        {
                            key: 'ready',
                            label: 'Ready to calibrate',
                            value: board.counts.submitted.toLocaleString(),
                            hint: 'submitted',
                            icon: Send,
                            accent: 'sky',
                        },
                        {
                            key: 'moved',
                            label: 'Moved',
                            value: board.counts.moved.toLocaleString(),
                            icon: Scale,
                            accent: 'violet',
                        },
                        {
                            key: 'held',
                            label: 'Held from employees',
                            value: board.counts.held.toLocaleString(),
                            hint: open ? 'until complete' : undefined,
                            icon: Hourglass,
                            accent: 'amber',
                        },
                    ]}
                />

                <div className="grid gap-4 xl:grid-cols-2">
                    <SpreadCard spread={board.spread} />
                    <CalibrationTable
                        rows={board.departments}
                        average={board.average}
                    />
                </div>

                <div className="flex flex-col gap-3">
                    <ListToolbar
                        filtered={
                            search !== '' ||
                            view !== 'all' ||
                            department !== 'all'
                        }
                        onReset={() => {
                            setSearch('');
                            setView('all');
                            setDepartment('all');
                        }}
                        summary={`${rows.length} of ${board.rows.length}`}
                    >
                        <SearchInput
                            value={search}
                            onSearch={setSearch}
                            delay={0}
                            placeholder="Search person or evaluator…"
                            label="Search appraisals"
                        />
                        <FilterSelect
                            label="Which appraisals"
                            value={view}
                            onChange={setView}
                            options={VIEWS}
                            className="w-52"
                        />
                        {departments.length > 1 && (
                            <FilterSelect
                                label="Filter by department"
                                value={department}
                                onChange={setDepartment}
                                options={[
                                    { value: 'all', label: 'All departments' },
                                    ...departments.map((d) => ({
                                        value: d,
                                        label: d,
                                    })),
                                ]}
                                className="w-48"
                            />
                        )}
                    </ListToolbar>

                    <TableCard>
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Employee</TableHead>
                                    <TableHead className="w-24 text-right">
                                        Attainment
                                    </TableHead>
                                    <TableHead className="w-44">
                                        Scorecard gave
                                    </TableHead>
                                    <TableHead className="w-48">
                                        Rated now
                                    </TableHead>
                                    <TableHead className="w-36 text-right">
                                        <span className="sr-only">Action</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rows.length === 0 ? (
                                    <EmptyTableRow
                                        colSpan={5}
                                        icon={Scale}
                                        title={
                                            board.rows.length === 0
                                                ? 'No appraisals in this session'
                                                : 'Nothing matches'
                                        }
                                        description={
                                            board.rows.length === 0
                                                ? 'Appraisals of the people it covers appear here as the cycle opens them.'
                                                : 'Try another view or department, or clear the search.'
                                        }
                                    />
                                ) : (
                                    rows.map((row) => (
                                        <TableRow key={row.id}>
                                            <TableCell className="whitespace-normal">
                                                <div className="flex min-w-52 items-center gap-3">
                                                    <PersonAvatar
                                                        name={
                                                            row.employee
                                                                ?.full_name ??
                                                            '?'
                                                        }
                                                        initials={
                                                            row.employee
                                                                ?.initials ??
                                                            '?'
                                                        }
                                                        photo={
                                                            row.employee?.photo
                                                        }
                                                        className="size-8"
                                                    />
                                                    <div className="min-w-0">
                                                        <Link
                                                            href={performanceRoutes.show(
                                                                row.hashid,
                                                            )}
                                                            className="block truncate text-sm font-medium hover:underline"
                                                        >
                                                            {
                                                                row.employee
                                                                    ?.full_name
                                                            }
                                                        </Link>
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {[
                                                                row.employee
                                                                    ?.department,
                                                                row.evaluator &&
                                                                    `rated by ${row.evaluator}`,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' · ')}
                                                        </p>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell className="text-right text-sm tabular-nums">
                                                {formatPercent(
                                                    row.overall_percent,
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {row.status === 'draft' ? (
                                                    <span className="text-xs text-muted-foreground">
                                                        Still being rated
                                                    </span>
                                                ) : (
                                                    <BandChip
                                                        label={
                                                            row.scored?.label ??
                                                            null
                                                        }
                                                        tone={row.scored?.tone}
                                                    />
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                {row.status !== 'draft' && (
                                                    <span className="inline-flex flex-wrap items-center gap-1.5">
                                                        {row.calibrated && (
                                                            <ArrowRight className="size-3.5 text-muted-foreground" />
                                                        )}
                                                        <BandChip
                                                            label={
                                                                row.current
                                                                    ?.label ??
                                                                null
                                                            }
                                                            tone={
                                                                row.current
                                                                    ?.tone
                                                            }
                                                        />
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-right">
                                                <RowAction
                                                    row={row}
                                                    manage={manage}
                                                    me={me}
                                                    onMove={() =>
                                                        setMoving(row)
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </DataTable>
                    </TableCard>
                </div>
            </PageBody>

            <AdjustRatingModal
                sessionHashid={session.hashid}
                row={
                    moving
                        ? (board.rows.find((r) => r.id === moving.id) ?? null)
                        : null
                }
                onOpenChange={(next) => !next && setMoving(null)}
            />

            {manage && (
                <SessionFormModal
                    open={editing}
                    onOpenChange={setEditing}
                    session={session}
                    periods={[]}
                    defaultPeriodId={null}
                    departments={[]}
                    calibrators={calibrators}
                />
            )}

            <ConfirmDialog
                open={confirm === 'complete'}
                onOpenChange={(next) => !next && setConfirm(null)}
                title="Complete this session?"
                description={
                    board.counts.held > 0
                        ? `Its ${board.counts.moved} ${board.counts.moved === 1 ? 'move stands' : 'moves stand'}, and the ${board.counts.held} ${board.counts.held === 1 ? 'appraisal it was holding is' : 'appraisals it was holding are'} shared with ${board.counts.held === 1 ? 'its employee' : 'their employees'}. Ratings can no longer be moved in it.`
                        : `Its ${board.counts.moved} ${board.counts.moved === 1 ? 'move stands' : 'moves stand'}. Ratings can no longer be moved in it.`
                }
                confirmLabel="Complete session"
                processing={processing}
                onConfirm={() => completeSession(session.hashid, handlers)}
            />

            <ConfirmDialog
                open={confirm === 'cancel'}
                onOpenChange={(next) => !next && setConfirm(null)}
                title="Cancel this session?"
                description="Nothing was moved in it. The appraisals it was holding are shared as they stand."
                confirmLabel="Cancel session"
                destructive
                processing={processing}
                onConfirm={() => cancelSession(session.hashid, handlers)}
            />
        </>
    );
}

/** What can be done with one appraisal in the session, or why not. */
function RowAction({
    row,
    manage,
    me,
    onMove,
}: {
    row: CalibrationRow;
    manage: boolean;
    me: number;
    onMove: () => void;
}) {
    if (row.status === 'acknowledged') {
        return (
            <span className="text-xs text-muted-foreground">
                Acknowledged — final
            </span>
        );
    }

    if (row.status === 'draft') {
        return null;
    }

    if (row.employee?.user_id === me) {
        return (
            <span className="text-xs text-muted-foreground">
                Your own — another calibrator decides
            </span>
        );
    }

    if (!manage) {
        return row.held ? (
            <span className="text-xs text-muted-foreground">Held</span>
        ) : null;
    }

    return (
        <Button variant="outline" size="sm" onClick={onMove}>
            <Scale className="size-4" />
            Move
        </Button>
    );
}

/**
 * How many sat in each band as the scorecards gave them, and as rated now —
 * whether the session changed the shape of the cycle, not just one card.
 */
function SpreadCard({ spread }: { spread: CalibrationSpread[] }) {
    const max = Math.max(1, ...spread.flatMap((b) => [b.before, b.after]));

    return (
        <TableCard
            title="Before and after"
            description="Each band as the scorecards gave it, and as rated now"
        >
            <DataTable className="[&_tbody_tr]:hover:bg-transparent">
                <TableHeader>
                    <TableRow>
                        <TableHead>Band</TableHead>
                        <TableHead className="w-20 text-right">
                            Scored
                        </TableHead>
                        <TableHead className="w-20 text-right">Now</TableHead>
                        <TableHead className="w-2/5">
                            <span className="sr-only">Spread</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {spread.length === 0 ? (
                        <EmptyTableRow
                            colSpan={4}
                            icon={Scale}
                            title="Nothing submitted yet"
                            description="The spread appears once appraisals in this session are submitted."
                        />
                    ) : (
                        spread.map((band) => {
                            const tone = bandTone(band.tone);
                            const delta = band.after - band.before;

                            return (
                                <TableRow key={band.label}>
                                    <TableCell>
                                        <span className="flex min-w-0 items-center gap-2 text-sm">
                                            <span
                                                className={cn(
                                                    'size-2.5 shrink-0 rounded-sm',
                                                    tone.fill,
                                                )}
                                                aria-hidden
                                            />
                                            <span className="max-w-48 truncate">
                                                {band.label}
                                            </span>
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                                        {band.before}
                                    </TableCell>
                                    <TableCell className="text-right text-sm font-medium tabular-nums">
                                        {band.after}
                                        {delta !== 0 && (
                                            <span
                                                className={cn(
                                                    'ml-1 text-xs',
                                                    delta > 0
                                                        ? 'text-emerald-600 dark:text-emerald-400'
                                                        : 'text-rose-600 dark:text-rose-400',
                                                )}
                                            >
                                                {delta > 0
                                                    ? `+${delta}`
                                                    : delta}
                                            </span>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-col gap-1">
                                            <div className="h-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-muted-foreground/40"
                                                    style={{
                                                        width: `${(band.before / max) * 100}%`,
                                                    }}
                                                />
                                            </div>
                                            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className={cn(
                                                        'h-full rounded-full',
                                                        tone.fill,
                                                    )}
                                                    style={{
                                                        width: `${(band.after / max) * 100}%`,
                                                    }}
                                                />
                                            </div>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            );
                        })
                    )}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}

CalibrationSessionPage.layout = (props: CalibrationSessionPageProps) => ({
    breadcrumbs: [
        { title: 'Performance Management', href: '/performance' },
        { title: 'Calibration', href: '/performance/calibration' },
        {
            title: props.session.name,
            href: performanceRoutes.session(props.session.hashid),
        },
    ],
});
