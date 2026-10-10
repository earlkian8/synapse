import { Head, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarRange,
    CheckCircle2,
    Clock3,
    Flag,
    Gauge,
    Plus,
    TrendingDown,
    TrendingUp,
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
import { GoalDialog } from '@/features/performance/components/goal-dialog';
import { GoalFormModal } from '@/features/performance/components/goal-form-modal';
import {
    GoalProgressBar,
    GoalStateChip,
} from '@/features/performance/components/goal-progress';
import { PerformanceNav } from '@/features/performance/components/performance-nav';
import { PeriodStatusBadge } from '@/features/performance/components/status-badge';
import {
    formatDate,
    formatPercent,
    sinceLabel,
} from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import type {
    GoalsPageProps,
    PerformanceGoal,
} from '@/features/performance/types';
import { cn } from '@/lib/utils';

const STATE_FILTERS = [
    { value: 'active', label: 'Active' },
    { value: 'on_track', label: 'On track' },
    { value: 'at_risk', label: 'At risk' },
    { value: 'off_track', label: 'Off track' },
    { value: 'quiet', label: 'Not checked in for a month' },
    { value: 'achieved', label: 'Achieved' },
    { value: 'missed', label: 'Missed' },
    { value: 'dropped', label: 'Dropped' },
    { value: 'all', label: 'All goals' },
];

function matchesState(goal: PerformanceGoal, state: string): boolean {
    switch (state) {
        case 'all':
            return true;
        case 'active':
            return goal.status === 'active';
        case 'quiet':
            return goal.is_stale;
        case 'on_track':
        case 'at_risk':
        case 'off_track':
            return goal.status === 'active' && goal.health === state;
        default:
            return goal.status === state;
    }
}

/**
 * Goals (ADR 0073): every goal set in a review cycle — whose, how far along,
 * how it is going and when anyone last checked in — so HR sees the cycle's
 * work before its appraisals. A goal opens to its check-ins; HR sets goals for
 * one person or many at once, from the library or written out.
 */
export default function Goals() {
    const {
        goals,
        stats,
        periods,
        currentPeriodId,
        templates,
        employees,
        departments,
        focus,
        can,
        nav,
    } = usePage<GoalsPageProps>().props;

    const [search, setSearch] = useState('');
    const [state, setState] = useState('active');
    const [department, setDepartment] = useState('all');
    const [openHashid, setOpenHashid] = useState<string | null>(focus);
    const [setting, setSetting] = useState(false);
    const [editing, setEditing] = useState<PerformanceGoal | null>(null);

    const period = periods.find((p) => p.id === currentPeriodId) ?? null;
    // The open goal is read from the page's props, so it refreshes after a
    // check-in like everything else.
    const opened = goals.find((g) => g.hashid === openHashid) ?? null;

    const rows = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return goals.filter(
            (goal) =>
                matchesState(goal, state) &&
                (department === 'all' ||
                    String(goal.employee?.department_id) === department) &&
                (needle === '' ||
                    [goal.title, goal.employee?.full_name]
                        .join(' ')
                        .toLowerCase()
                        .includes(needle)),
        );
    }, [goals, search, state, department]);

    const page = useClientPagination(
        rows,
        [currentPeriodId, search, state, department].join('|'),
    );

    const openPeriods = periods.filter(
        (p) => p.status !== 'closed' && !p.is_archived,
    );

    return (
        <>
            <Head title="Goals" />

            <PageBody>
                <PageHeader
                    title="Goals"
                    description={
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <span className="flex items-center gap-1.5">
                                <CalendarRange className="size-4" />
                                Review cycle
                            </span>
                            <Select
                                value={
                                    currentPeriodId === null
                                        ? ''
                                        : String(currentPeriodId)
                                }
                                onValueChange={(value) =>
                                    router.get(
                                        performanceRoutes.goals(Number(value)),
                                        {},
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="w-56 text-foreground"
                                    aria-label="Review cycle"
                                >
                                    <SelectValue placeholder="No cycles yet" />
                                </SelectTrigger>
                                <SelectContent>
                                    {periods.map((p) => (
                                        <SelectItem
                                            key={p.id}
                                            value={String(p.id)}
                                        >
                                            {p.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {period && (
                                <PeriodStatusBadge status={period.status} />
                            )}
                        </div>
                    }
                    actions={<PerformanceNav current="goals" counts={nav} />}
                />

                <StatTiles
                    tiles={[
                        {
                            key: 'total',
                            label: 'Goals',
                            value: stats.total.toLocaleString(),
                            hint: `for ${stats.people} ${stats.people === 1 ? 'person' : 'people'}`,
                            icon: Flag,
                            accent: 'teal',
                        },
                        {
                            key: 'on_track',
                            label: 'On track',
                            value: stats.on_track.toLocaleString(),
                            icon: TrendingUp,
                            accent: 'emerald',
                        },
                        {
                            key: 'at_risk',
                            label: 'At risk or off track',
                            value: (
                                stats.at_risk + stats.off_track
                            ).toLocaleString(),
                            hint:
                                stats.off_track > 0
                                    ? `${stats.off_track} off track`
                                    : undefined,
                            icon: TrendingDown,
                            accent: 'amber',
                            valueClassName:
                                stats.off_track > 0
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : undefined,
                        },
                        {
                            key: 'stale',
                            label: 'Quiet for a month',
                            value: stats.stale.toLocaleString(),
                            hint: 'no check-in',
                            icon: Clock3,
                            accent: 'slate',
                        },
                        {
                            key: 'progress',
                            label: 'Average progress',
                            value: formatPercent(stats.average_progress, 0),
                            hint: `${stats.achieved} achieved`,
                            icon: Gauge,
                            accent: 'sky',
                        },
                    ]}
                />

                <div className="flex flex-col gap-3">
                    <ListToolbar
                        filtered={
                            search !== '' ||
                            state !== 'active' ||
                            department !== 'all'
                        }
                        onReset={() => {
                            setSearch('');
                            setState('active');
                            setDepartment('all');
                        }}
                        summary={
                            goals.length > 0
                                ? `${rows.length} of ${goals.length}`
                                : undefined
                        }
                        actions={
                            can.manage && (
                                <Button
                                    size="sm"
                                    onClick={() => setSetting(true)}
                                    disabled={openPeriods.length === 0}
                                >
                                    <Plus className="size-4" />
                                    Set a goal
                                </Button>
                            )
                        }
                    >
                        <SearchInput
                            value={search}
                            onSearch={setSearch}
                            delay={0}
                            placeholder="Search goal or person…"
                            label="Search goals"
                        />
                        <FilterSelect
                            label="Filter by state"
                            value={state}
                            onChange={setState}
                            options={STATE_FILTERS}
                            className="w-52"
                        />
                        <FilterSelect
                            label="Filter by department"
                            value={department}
                            onChange={setDepartment}
                            options={[
                                { value: 'all', label: 'All departments' },
                                ...departments.map((d) => ({
                                    value: String(d.id),
                                    label: d.name,
                                })),
                            ]}
                            className="w-48"
                        />
                    </ListToolbar>

                    <TableCard>
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Goal</TableHead>
                                    <TableHead className="w-64">
                                        Progress
                                    </TableHead>
                                    <TableHead className="w-32">
                                        State
                                    </TableHead>
                                    <TableHead className="w-36">
                                        Last check-in
                                    </TableHead>
                                    <TableHead className="w-28">Due</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {page.rows.length === 0 ? (
                                    <EmptyTableRow
                                        colSpan={5}
                                        icon={Flag}
                                        title={
                                            goals.length === 0
                                                ? 'No goals in this cycle'
                                                : 'No goals match'
                                        }
                                        description={
                                            goals.length === 0
                                                ? can.manage
                                                    ? 'Set goals for people, one at a time or a whole team at once. Employees can add their own once the cycle is open.'
                                                    : 'Nobody has goals in this cycle yet.'
                                                : 'Try another state or department, or clear the search.'
                                        }
                                    />
                                ) : (
                                    page.rows.map((goal) => (
                                        <TableRow
                                            key={goal.id}
                                            {...rowOpens(() =>
                                                setOpenHashid(goal.hashid),
                                            )}
                                        >
                                            <TableCell className="whitespace-normal">
                                                <div className="flex min-w-56 items-center gap-3">
                                                    <PersonAvatar
                                                        name={
                                                            goal.employee
                                                                ?.full_name ??
                                                            '?'
                                                        }
                                                        initials={
                                                            goal.employee
                                                                ?.initials ??
                                                            '?'
                                                        }
                                                        photo={
                                                            goal.employee?.photo
                                                        }
                                                        className="size-8"
                                                    />
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-medium">
                                                            {goal.title}
                                                        </p>
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {[
                                                                goal.employee
                                                                    ?.full_name,
                                                                goal.employee
                                                                    ?.department,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' · ')}
                                                        </p>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <GoalProgressBar goal={goal} />
                                            </TableCell>
                                            <TableCell>
                                                <GoalStateChip goal={goal} />
                                            </TableCell>
                                            <TableCell
                                                className={cn(
                                                    'text-sm text-muted-foreground',
                                                    goal.is_stale &&
                                                        'text-amber-700 dark:text-amber-400',
                                                )}
                                            >
                                                <span className="inline-flex items-center gap-1">
                                                    {goal.is_stale && (
                                                        <AlertTriangle className="size-3.5" />
                                                    )}
                                                    {sinceLabel(
                                                        goal.last_check_in_at,
                                                    )}
                                                </span>
                                            </TableCell>
                                            <TableCell className="text-sm text-muted-foreground tabular-nums">
                                                {goal.status === 'achieved' ? (
                                                    <CheckCircle2 className="size-4 text-emerald-600 dark:text-emerald-400" />
                                                ) : (
                                                    formatDate(goal.due_on)
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
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

            <GoalDialog
                goal={opened}
                onOpenChange={(open) => !open && setOpenHashid(null)}
                as="manager"
                canManage={can.manage}
                onEdit={(goal) => {
                    setOpenHashid(null);
                    setEditing(goal);
                }}
            />

            {can.manage && (
                <GoalFormModal
                    open={setting}
                    onOpenChange={setSetting}
                    mode={{ kind: 'assign', employees, departments }}
                    periods={openPeriods}
                    defaultPeriodId={currentPeriodId}
                    templates={templates}
                />
            )}

            {editing && (
                <GoalFormModal
                    key={editing.hashid}
                    open
                    onOpenChange={(open) => !open && setEditing(null)}
                    mode={{ kind: 'edit', goal: editing }}
                    periods={openPeriods}
                    defaultPeriodId={currentPeriodId}
                    templates={templates}
                />
            )}
        </>
    );
}

Goals.layout = {
    breadcrumbs: [
        { title: 'Performance Management', href: '/performance' },
        { title: 'Goals', href: '/performance/goals' },
    ],
};
