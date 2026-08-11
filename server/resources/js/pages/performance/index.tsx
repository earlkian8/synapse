import { Head, router, usePage } from '@inertiajs/react';
import {
    CalendarRange,
    Download,
    Gauge,
    Plus,
    Rocket,
    Search,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { BandDistribution } from '@/features/performance/components/band-distribution';
import { CalibrationTable } from '@/features/performance/components/calibration-table';
import { EvaluationTable } from '@/features/performance/components/evaluation-table';
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

    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return evaluations.filter((evaluation) => {
            if (statusFilter !== 'all' && evaluation.status !== statusFilter) {
                return false;
            }

            return (
                needle === '' ||
                (evaluation.employee?.full_name
                    .toLowerCase()
                    .includes(needle) ??
                    false)
            );
        });
    }, [evaluations, statusFilter, search]);

    const completed = evaluations.filter(
        (evaluation) => evaluation.result_label !== null,
    ).length;

    return (
        <>
            <Head title="Performance Management" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                {/* The cycle is the unit of work, so it leads the page. */}
                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex min-w-0 flex-col gap-2">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Performance Management
                        </h1>
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
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
                                <SelectTrigger className="w-56">
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
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {formatDate(period.start_date)} –{' '}
                                        {formatDate(period.end_date)}
                                    </span>
                                </>
                            )}
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        {evaluations.length > 0 && (
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
                        )}
                        {can.manage && (
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
                        )}
                    </div>
                </div>

                <PerformanceStatsCards stats={stats} />

                {evaluations.length === 0 ? (
                    <EmptyState canManage={can.manage} />
                ) : (
                    <>
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

                        <div className="flex flex-col gap-3">
                            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                <div className="relative sm:max-w-xs sm:flex-1">
                                    <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input
                                        value={search}
                                        onChange={(event) =>
                                            setSearch(event.target.value)
                                        }
                                        placeholder="Search employee…"
                                        aria-label="Search appraisals by employee"
                                        className="pl-8"
                                    />
                                </div>
                                <Select
                                    value={statusFilter}
                                    onValueChange={(value) =>
                                        setStatusFilter(
                                            value as EvaluationStatus | 'all',
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        className="sm:w-52"
                                        aria-label="Filter by status"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {STATUS_FILTERS.map((status) => (
                                            <SelectItem
                                                key={status.value}
                                                value={status.value}
                                            >
                                                {status.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <span className="text-xs text-muted-foreground tabular-nums sm:ml-auto">
                                    {filtered.length} of {evaluations.length}
                                </span>
                            </div>

                            {filtered.length === 0 ? (
                                <p className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-10 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                                    No appraisals match these filters.
                                </p>
                            ) : (
                                <EvaluationTable evaluations={filtered} />
                            )}
                        </div>
                    </>
                )}
            </div>

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

function EmptyState({ canManage }: { canManage: boolean }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Gauge className="size-5" />
            </span>
            <p className="text-sm font-medium">
                Nothing appraised in this cycle
            </p>
            <p className="max-w-sm text-sm text-muted-foreground">
                {canManage
                    ? 'Launch the cycle to open an appraisal for everyone at once, or open one at a time.'
                    : 'No appraisals have been conducted in this cycle yet.'}
            </p>
        </div>
    );
}

PerformanceIndex.layout = {
    breadcrumbs: [{ title: 'Performance', href: '/performance' }],
};
