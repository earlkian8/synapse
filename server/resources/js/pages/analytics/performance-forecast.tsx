import { Head, usePage } from '@inertiajs/react';
import {
    CalendarRange,
    ChevronRight,
    History,
    LineChart,
    Search,
    Sparkles,
    Trash2,
} from 'lucide-react';
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
import { Spinner } from '@/components/ui/spinner';
import {
    deleteRun,
    runForecast,
    viewRun,
} from '@/features/performance-forecast/api';
import { BandBadge } from '@/features/performance-forecast/components/band-badge';
import { EmployeeDetailDialog } from '@/features/performance-forecast/components/employee-detail-dialog';
import { ForecastStatsCards } from '@/features/performance-forecast/components/forecast-stats';
import { ServiceBanner } from '@/features/performance-forecast/components/service-banner';
import {
    formatConfidence,
    formatRating,
    formatRelative,
    ratingTone,
} from '@/features/performance-forecast/constants';
import type {
    ForecastBand,
    ForecastScore,
    PerformanceForecastPageProps,
} from '@/features/performance-forecast/types';
import { cn } from '@/lib/utils';

const BAND_FILTERS: { value: ForecastBand | 'all'; label: string }[] = [
    { value: 'all', label: 'All bands' },
    { value: 'exceeds', label: 'Exceeds' },
    { value: 'on_track', label: 'On track' },
    { value: 'below', label: 'Below target' },
];

export default function PerformanceForecast() {
    const { run, runs, service, can } =
        usePage<PerformanceForecastPageProps>().props;

    const [processing, setProcessing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [search, setSearch] = useState('');
    const [bandFilter, setBandFilter] = useState<ForecastBand | 'all'>('all');
    const [detail, setDetail] = useState<ForecastScore | null>(null);

    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return (run?.forecasts ?? []).filter((score) => {
            if (bandFilter !== 'all' && score.band !== bandFilter) {
                return false;
            }

            if (
                needle !== '' &&
                !score.employee?.full_name.toLowerCase().includes(needle)
            ) {
                return false;
            }

            return true;
        });
    }, [run, search, bandFilter]);

    const handleRun = () => {
        runForecast({
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
        });
    };

    const handleDelete = () => {
        if (!run || !window.confirm('Delete this forecast run?')) {
            return;
        }

        deleteRun(run.hashid, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <>
            <Head title="Performance Forecast" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                            <LineChart className="size-5 text-[#0ABFBF]" />
                            Performance Forecast
                        </h1>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            A model-projected next-period rating for every
                            active employee — from their evaluation history,
                            tenure and credentials — to plan reviews, coaching
                            and development before the cycle begins.
                        </p>
                    </div>
                    {can.manage && (
                        <Button
                            size="sm"
                            onClick={handleRun}
                            disabled={processing || !service.connected}
                        >
                            {processing ? (
                                <Spinner />
                            ) : (
                                <Sparkles className="size-4" />
                            )}
                            {processing ? 'Forecasting…' : 'Run forecast'}
                        </Button>
                    )}
                </div>

                <ServiceBanner service={service} />

                {!run ? (
                    <EmptyState
                        canManage={can.manage}
                        connected={service.connected}
                        processing={processing}
                        onRun={handleRun}
                    />
                ) : (
                    <>
                        <ForecastStatsCards run={run} />

                        {/* Run meta + history */}
                        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                            <span className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <span>
                                    Last run {formatRelative(run.created_at)}
                                    {run.generated_by
                                        ? ` by ${run.generated_by}`
                                        : ''}
                                </span>
                                {run.target_period && (
                                    <span className="flex items-center gap-1">
                                        <CalendarRange className="size-3.5" />
                                        Forecasting{' '}
                                        <span className="font-medium text-foreground">
                                            {run.target_period.name}
                                        </span>
                                    </span>
                                )}
                            </span>
                            <div className="flex items-center gap-2">
                                {runs.length > 1 && (
                                    <div className="flex items-center gap-1.5">
                                        <History className="size-3.5" />
                                        <Select
                                            value={run.hashid}
                                            onValueChange={viewRun}
                                        >
                                            <SelectTrigger className="h-8 w-56 text-xs">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {runs.map((r) => (
                                                    <SelectItem
                                                        key={r.hashid}
                                                        value={r.hashid}
                                                    >
                                                        {formatRelative(
                                                            r.created_at,
                                                        )}{' '}
                                                        · {r.employees_scored}{' '}
                                                        scored ·{' '}
                                                        {r.exceeds_count}{' '}
                                                        exceeding
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}
                                {can.manage && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="h-8 text-muted-foreground hover:text-destructive"
                                        onClick={handleDelete}
                                        disabled={deleting}
                                    >
                                        <Trash2 className="size-3.5" />
                                        Delete
                                    </Button>
                                )}
                            </div>
                        </div>

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
                                value={bandFilter}
                                onValueChange={(v) =>
                                    setBandFilter(v as ForecastBand | 'all')
                                }
                            >
                                <SelectTrigger className="sm:w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {BAND_FILTERS.map((b) => (
                                        <SelectItem
                                            key={b.value}
                                            value={b.value}
                                        >
                                            {b.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Ranked list */}
                        {filtered.length === 0 ? (
                            <p className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-10 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                                No employees match these filters.
                            </p>
                        ) : (
                            <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                                <ul className="divide-y divide-border">
                                    {filtered.map((score, index) => (
                                        <ForecastRow
                                            key={score.id}
                                            rank={index + 1}
                                            score={score}
                                            onOpen={() => setDetail(score)}
                                        />
                                    ))}
                                </ul>
                            </div>
                        )}
                    </>
                )}
            </div>

            <EmployeeDetailDialog
                score={detail}
                onOpenChange={(open) => !open && setDetail(null)}
            />
        </>
    );
}

function ForecastRow({
    rank,
    score,
    onOpen,
}: {
    rank: number;
    score: ForecastScore;
    onOpen: () => void;
}) {
    const previous = score.history.at(-1)?.rating ?? null;
    const delta =
        previous === null
            ? null
            : Math.round(score.predicted_rating - previous);

    return (
        <li>
            <button
                type="button"
                onClick={onOpen}
                className="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/50"
            >
                <span className="w-5 shrink-0 text-center text-xs font-medium text-muted-foreground tabular-nums">
                    {rank}
                </span>
                <PersonAvatar
                    name={score.employee?.full_name ?? 'Unknown'}
                    initials={score.employee?.initials ?? '?'}
                    photo={score.employee?.photo}
                    className="size-9"
                />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">
                        {score.employee?.full_name ?? 'Unknown employee'}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {score.employee?.position ??
                            score.employee?.employee_no ??
                            '—'}
                        {delta !== null
                            ? ` · ${delta >= 0 ? '+' : ''}${delta} vs last cycle`
                            : ` · ${formatConfidence(score.confidence)} confidence`}
                    </p>
                </div>
                <div className="flex w-12 flex-col items-end">
                    <span
                        className={cn(
                            'text-sm font-semibold tabular-nums',
                            ratingTone(score.predicted_rating),
                        )}
                    >
                        {formatRating(score.predicted_rating)}
                    </span>
                    <span className="text-[10px] tracking-wide text-muted-foreground uppercase">
                        / 100
                    </span>
                </div>
                <BandBadge band={score.band} />
                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
            </button>
        </li>
    );
}

function EmptyState({
    canManage,
    connected,
    processing,
    onRun,
}: {
    canManage: boolean;
    connected: boolean;
    processing: boolean;
    onRun: () => void;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-12 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <LineChart className="size-6" />
            </span>
            <p className="text-sm font-medium">No forecast yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                {canManage
                    ? 'Run a forecast to project every active employee’s next-period performance rating using the trained model.'
                    : 'No performance forecast has been run yet.'}
            </p>
            {canManage && (
                <Button
                    className="mt-1"
                    onClick={onRun}
                    disabled={processing || !connected}
                >
                    {processing ? <Spinner /> : <Sparkles className="size-4" />}
                    {processing ? 'Forecasting…' : 'Run first forecast'}
                </Button>
            )}
        </div>
    );
}

PerformanceForecast.layout = {
    breadcrumbs: [
        { title: 'Analytics', href: '/analytics/performance-forecast' },
        {
            title: 'Performance Forecast',
            href: '/analytics/performance-forecast',
        },
    ],
};
