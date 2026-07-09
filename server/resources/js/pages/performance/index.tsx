import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, Download, Gauge, Plus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { NewEvaluationDialog } from '@/features/performance/components/new-evaluation-dialog';
import { PerformanceStatsCards } from '@/features/performance/components/performance-stats';
import { EvaluationStatusBadge } from '@/features/performance/components/status-badge';
import { formatScore, scoreTone } from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import type {
    EvaluationStatus,
    PerformanceEvaluation,
    PerformanceIndexPageProps,
} from '@/features/performance/types';

const STATUS_FILTERS: { value: EvaluationStatus | 'all'; label: string }[] = [
    { value: 'all', label: 'All statuses' },
    { value: 'draft', label: 'Draft' },
    { value: 'submitted', label: 'Submitted' },
    { value: 'acknowledged', label: 'Acknowledged' },
];

export default function PerformanceIndex() {
    const { evaluations, periods, employees, stats, can } =
        usePage<PerformanceIndexPageProps>().props;

    const [newOpen, setNewOpen] = useState(false);
    const [periodFilter, setPeriodFilter] = useState('all');
    const [statusFilter, setStatusFilter] = useState<EvaluationStatus | 'all'>(
        'all',
    );
    const [search, setSearch] = useState('');

    // `${periodId}:${employeeId}` pairs already evaluated, for the new dialog.
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
            if (
                periodFilter !== 'all' &&
                String(evaluation.period?.id) !== periodFilter
            ) {
                return false;
            }

            if (statusFilter !== 'all' && evaluation.status !== statusFilter) {
                return false;
            }

            if (
                needle !== '' &&
                !evaluation.employee?.full_name.toLowerCase().includes(needle)
            ) {
                return false;
            }

            return true;
        });
    }, [evaluations, periodFilter, statusFilter, search]);

    return (
        <>
            <Head title="Performance Management" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Performance Management
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Conduct KPI evaluations across review periods and
                            track their scores.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {evaluations.length > 0 && (
                            <Button variant="outline" size="sm" asChild>
                                <a href={performanceRoutes.export}>
                                    <Download className="size-4" />
                                    Export
                                </a>
                            </Button>
                        )}
                        {can.manage && (
                            <Button size="sm" onClick={() => setNewOpen(true)}>
                                <Plus className="size-4" />
                                New evaluation
                            </Button>
                        )}
                    </div>
                </div>

                <PerformanceStatsCards stats={stats} />

                {evaluations.length === 0 ? (
                    <EmptyState canManage={can.manage} />
                ) : (
                    <div className="flex flex-col gap-3">
                        {/* Filters */}
                        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                            <div className="relative sm:max-w-xs sm:flex-1">
                                <Search className="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search employee…"
                                    className="pl-8"
                                />
                            </div>
                            <Select
                                value={periodFilter}
                                onValueChange={setPeriodFilter}
                            >
                                <SelectTrigger className="sm:w-52">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All periods
                                    </SelectItem>
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
                            <Select
                                value={statusFilter}
                                onValueChange={(v) =>
                                    setStatusFilter(
                                        v as EvaluationStatus | 'all',
                                    )
                                }
                            >
                                <SelectTrigger className="sm:w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {STATUS_FILTERS.map((s) => (
                                        <SelectItem
                                            key={s.value}
                                            value={s.value}
                                        >
                                            {s.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {filtered.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-10 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                                No evaluations match these filters.
                            </p>
                        ) : (
                            <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                                <ul className="divide-y divide-border">
                                    {filtered.map((evaluation) => (
                                        <EvaluationRow
                                            key={evaluation.id}
                                            evaluation={evaluation}
                                        />
                                    ))}
                                </ul>
                            </div>
                        )}
                    </div>
                )}
            </div>

            <NewEvaluationDialog
                open={newOpen}
                onOpenChange={setNewOpen}
                periods={periods}
                employees={employees}
                taken={taken}
            />
        </>
    );
}

function EvaluationRow({ evaluation }: { evaluation: PerformanceEvaluation }) {
    return (
        <li>
            <Link
                href={performanceRoutes.show(evaluation.hashid)}
                className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-muted/50"
            >
                <PersonAvatar
                    name={evaluation.employee?.full_name ?? 'Unknown'}
                    initials={evaluation.employee?.initials ?? '?'}
                    photo={evaluation.employee?.photo}
                    className="size-9"
                />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">
                        {evaluation.employee?.full_name ?? 'Unknown employee'}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {evaluation.employee?.position ??
                            evaluation.employee?.employee_no ??
                            '—'}
                    </p>
                </div>
                <span className="hidden max-w-[10rem] min-w-0 truncate text-xs text-muted-foreground sm:block">
                    {evaluation.period?.name ?? '—'}
                </span>
                <div className="flex w-16 flex-col items-end">
                    <span
                        className={`text-sm font-semibold tabular-nums ${scoreTone(evaluation.overall_score)}`}
                    >
                        {formatScore(evaluation.overall_score)}
                    </span>
                    <span className="text-[10px] tracking-wide text-muted-foreground uppercase">
                        / 5.00
                    </span>
                </div>
                <EvaluationStatusBadge status={evaluation.status} />
                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
            </Link>
        </li>
    );
}

function EmptyState({ canManage }: { canManage: boolean }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Gauge className="size-5" />
            </span>
            <p className="text-sm font-medium">No evaluations yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                {canManage
                    ? 'Set up KPI criteria and an open review period under Company Setup, then open an evaluation here.'
                    : 'No performance evaluations have been conducted yet.'}
            </p>
        </div>
    );
}

PerformanceIndex.layout = {
    breadcrumbs: [{ title: 'Performance', href: '/performance' }],
};
