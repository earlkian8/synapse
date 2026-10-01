import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarRange, Download, Plus, Rocket } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    SearchInput,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { kpiConfigRoutes } from '@/features/kpi-config/routes';
import { BandDistribution } from '@/features/performance/components/band-distribution';
import { CalibrationTable } from '@/features/performance/components/calibration-table';
import { EvaluationTable } from '@/features/performance/components/evaluation-table';
import type { EvaluationSort } from '@/features/performance/components/evaluation-table';
import { LaunchCycleModal } from '@/features/performance/components/launch-cycle-modal';
import { OpenAppraisalModal } from '@/features/performance/components/open-appraisal-modal';
import { PerformanceStatsCards } from '@/features/performance/components/performance-stats';
import { PeriodStatusBadge } from '@/features/performance/components/status-badge';
import { formatDate } from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import type {
    EvaluationStatus,
    PerformanceIndexPageProps,
} from '@/features/performance/types';

const STATUS_FILTERS: { value: EvaluationStatus | 'all'; label: string }[] = [
    { value: 'all', label: 'All statuses' },
    { value: 'draft', label: 'In progress' },
    { value: 'submitted', label: 'Awaiting sign-off' },
    { value: 'acknowledged', label: 'Signed off' },
];

/** Rank an appraisal by its lifecycle for the table's status sort. */
const STATUS_RANK: Record<EvaluationStatus, number> = {
    draft: 0,
    submitted: 1,
    acknowledged: 2,
};

/**
 * Performance Management: the review cycle leads the page, then where it
 * stands, how it was rated, and one table of every appraisal in it.
 */
export default function PerformanceIndex() {
    const {
        evaluations,
        periods,
        templates,
        departments,
        employees,
        currentPeriodId,
        stats,
        distribution,
        byDepartment,
        can,
    } = usePage<PerformanceIndexPageProps>().props;

    const [openAppraisal, setOpenAppraisal] = useState(false);
    const [launchOpen, setLaunchOpen] = useState(false);
    const [statusFilter, setStatusFilter] = useState<EvaluationStatus | 'all'>(
        'all',
    );
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<EvaluationSort>('name');
    const [direction, setDirection] = useState<'asc' | 'desc'>('asc');

    const period = periods.find((p) => p.id === currentPeriodId) ?? null;

    // `${periodId}:${employeeId}` pairs already appraised in the shown cycle.
    const taken = useMemo(() => {
        const set = new Set<string>();

        for (const evaluation of evaluations) {
            if (evaluation.period && evaluation.employee) {
                set.add(`${evaluation.period.id}:${evaluation.employee.id}`);
            }
        }

        return set;
    }, [evaluations]);

    const onSort = (key: EvaluationSort) => {
        if (key === sort) {
            setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSort(key);
            setDirection(key === 'result' ? 'desc' : 'asc');
        }
    };

    const rows = useMemo(() => {
        const needle = search.trim().toLowerCase();
        const dir = direction === 'asc' ? 1 : -1;

        return evaluations
            .filter((evaluation) => {
                if (
                    statusFilter !== 'all' &&
                    evaluation.status !== statusFilter
                ) {
                    return false;
                }

                return (
                    needle === '' ||
                    [
                        evaluation.employee?.full_name,
                        evaluation.employee?.position,
                        evaluation.employee?.department,
                    ].some((field) =>
                        (field ?? '').toLowerCase().includes(needle),
                    )
                );
            })
            .sort((a, b) => {
                switch (sort) {
                    case 'framework':
                        return (
                            (a.template_name ?? '').localeCompare(
                                b.template_name ?? '',
                            ) * dir
                        );
                    case 'result':
                        return (
                            ((a.overall_percent ?? -1) -
                                (b.overall_percent ?? -1)) *
                            dir
                        );
                    case 'status':
                        return (
                            (STATUS_RANK[a.status] - STATUS_RANK[b.status]) *
                            dir
                        );
                    default:
                        return (
                            (a.employee?.full_name ?? '').localeCompare(
                                b.employee?.full_name ?? '',
                            ) * dir
                        );
                }
            });
    }, [evaluations, statusFilter, search, sort, direction]);

    const page = useClientPagination(
        rows,
        [currentPeriodId, statusFilter, search, sort, direction].join('|'),
    );

    const completed = evaluations.filter(
        (evaluation) => evaluation.result_label !== null,
    ).length;

    const isFiltered = search !== '' || statusFilter !== 'all';

    return (
        <>
            <Head title="Performance Management" />

            <PageBody>
                {/* The cycle is the unit of work, so it leads the page. */}
                <PageHeader
                    title="Performance Management"
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
                                        performanceRoutes.forPeriod(
                                            Number(value),
                                        ),
                                        {},
                                        {
                                            preserveScroll: true,
                                            preserveState: true,
                                        },
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
                                <>
                                    <PeriodStatusBadge status={period.status} />
                                    <span className="text-xs tabular-nums">
                                        {formatDate(period.start_date)} –{' '}
                                        {formatDate(period.end_date)}
                                    </span>
                                </>
                            )}
                        </div>
                    }
                    actions={
                        can.manage && (
                            <>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setOpenAppraisal(true)}
                                >
                                    <Plus className="size-4" />
                                    Open one
                                </Button>
                                <Button
                                    size="sm"
                                    onClick={() => setLaunchOpen(true)}
                                >
                                    <Rocket className="size-4" />
                                    Launch cycle
                                </Button>
                            </>
                        )
                    }
                />

                <PerformanceStatsCards stats={stats} />

                {evaluations.length > 0 && (
                    <div className="grid gap-4 xl:grid-cols-2">
                        <BandDistribution
                            distribution={distribution}
                            total={completed}
                        />
                        <CalibrationTable
                            rows={byDepartment}
                            average={stats.average_percent}
                        />
                    </div>
                )}

                <div className="flex flex-col gap-3">
                    <ListToolbar
                        filtered={isFiltered}
                        onReset={() => {
                            setSearch('');
                            setStatusFilter('all');
                        }}
                        summary={
                            evaluations.length > 0
                                ? `${rows.length} of ${evaluations.length}`
                                : undefined
                        }
                        actions={
                            evaluations.length > 0 && (
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={performanceRoutes.export(
                                            currentPeriodId,
                                        )}
                                    >
                                        <Download className="size-4" />
                                        Export
                                    </a>
                                </Button>
                            )
                        }
                    >
                        <SearchInput
                            value={search}
                            onSearch={setSearch}
                            delay={0}
                            placeholder="Search employee, position…"
                            label="Search appraisals"
                        />
                        <FilterSelect
                            label="Filter by status"
                            value={statusFilter}
                            onChange={(value) =>
                                setStatusFilter(
                                    value as EvaluationStatus | 'all',
                                )
                            }
                            options={STATUS_FILTERS}
                            className="w-44"
                        />
                    </ListToolbar>

                    <EvaluationTable
                        evaluations={page.rows}
                        sort={sort}
                        direction={direction}
                        onSort={onSort}
                        empty={
                            evaluations.length > 0
                                ? {
                                      title: 'No appraisals match',
                                      description:
                                          'Try another status, or clear the search.',
                                  }
                                : emptyCycle({
                                      canManage: can.manage,
                                      hasFramework: templates.length > 0,
                                      hasOpenCycle: periods.some(
                                          (p) =>
                                              p.status === 'open' &&
                                              !p.is_archived,
                                      ),
                                  })
                        }
                    />

                    <TablePagination
                        meta={page.meta}
                        perPage={page.perPage}
                        onPage={page.setPage}
                        onPerPage={page.setPerPage}
                    />
                </div>
            </PageBody>

            <OpenAppraisalModal
                open={openAppraisal}
                onOpenChange={setOpenAppraisal}
                periods={periods}
                templates={templates}
                employees={employees}
                taken={taken}
                defaultPeriodId={currentPeriodId}
            />

            <LaunchCycleModal
                open={launchOpen}
                onOpenChange={setLaunchOpen}
                periods={periods}
                templates={templates}
                departments={departments}
                employees={employees}
                taken={taken}
                defaultPeriodId={currentPeriodId}
            />
        </>
    );
}

/**
 * An empty cycle means one of three different things, and each has a different
 * next step. Telling somebody to "launch the cycle" when they have no framework
 * to launch it with sends them to a modal that can only refuse them.
 */
function emptyCycle({
    canManage,
    hasFramework,
    hasOpenCycle,
}: {
    canManage: boolean;
    hasFramework: boolean;
    hasOpenCycle: boolean;
}) {
    if (!canManage) {
        return {
            title: 'Nothing appraised in this cycle',
            description: 'No appraisals have been conducted in this cycle yet.',
        };
    }

    const blocked = !hasFramework
        ? {
              title: 'No appraisal framework yet',
              description:
                  'A framework decides what gets measured and how the result is reported. Build one first.',
              cta: 'Set up a framework',
          }
        : !hasOpenCycle
          ? {
                title: 'No review cycle is open',
                description:
                    'Appraisals are conducted inside a cycle. Open one to start reviewing.',
                cta: 'Open a review cycle',
            }
          : {
                title: 'Nothing appraised in this cycle',
                description:
                    'Launch the cycle to open an appraisal for everyone at once, or open them one at a time.',
                cta: null,
            };

    return {
        title: blocked.title,
        description: blocked.description,
        action: blocked.cta ? (
            <Button variant="outline" size="sm" asChild>
                <Link href={kpiConfigRoutes.index}>{blocked.cta}</Link>
            </Button>
        ) : undefined,
    };
}

PerformanceIndex.layout = {
    breadcrumbs: [{ title: 'Performance', href: '/performance' }],
};
