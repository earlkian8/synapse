import { Head } from '@inertiajs/react';
import {
    ChevronRight,
    History,
    Search,
    Sparkles,
    Trash2,
    TrendingDown,
} from 'lucide-react';
import { useMemo, useState, useSyncExternalStore } from 'react';
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
import { deleteRun, runAssessment } from '@/features/attrition-risk/api';
import { DemoBanner } from '@/features/attrition-risk/components/demo-banner';
import { EmployeeDetailDialog } from '@/features/attrition-risk/components/employee-detail-dialog';
import { RiskBadge } from '@/features/attrition-risk/components/risk-badge';
import { RiskStatsCards } from '@/features/attrition-risk/components/risk-stats';
import {
    formatConfidence,
    formatRelative,
    formatScore,
    scoreTone,
} from '@/features/attrition-risk/constants';
import {
    getRunsSnapshot,
    getServerRunsSnapshot,
    subscribeRuns,
    toSummary,
} from '@/features/attrition-risk/mock-engine';
import type { RiskScore, RiskTier } from '@/features/attrition-risk/types';
import { cn } from '@/lib/utils';

const TIER_FILTERS: { value: RiskTier | 'all'; label: string }[] = [
    { value: 'all', label: 'All tiers' },
    { value: 'high', label: 'High risk' },
    { value: 'medium', label: 'At watch' },
    { value: 'low', label: 'Stable' },
];

export default function AttritionRisk() {
    // Attrition Risk is a frontend-only demo (no server data behind it) — runs
    // live in localStorage, seeded with one run on first visit. Read via
    // useSyncExternalStore (the same pattern as useAppearance/useIsMobile in
    // this codebase) rather than a useEffect + setState: its getServerSnapshot
    // keeps the SSR pass and the first client render both rendering "no runs
    // yet", so the real, randomly-seeded scores only ever appear client-side —
    // no hydration mismatch. See mock-engine.ts.
    const runs = useSyncExternalStore(
        subscribeRuns,
        getRunsSnapshot,
        getServerRunsSnapshot,
    );
    const [activeHashid, setActiveHashid] = useState<string | null>(null);

    const [processing, setProcessing] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [search, setSearch] = useState('');
    const [tierFilter, setTierFilter] = useState<RiskTier | 'all'>('all');
    const [detail, setDetail] = useState<RiskScore | null>(null);

    const run = runs.find((r) => r.hashid === activeHashid) ?? runs[0] ?? null;
    const runSummaries = useMemo(() => runs.map(toSummary), [runs]);

    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return (run?.scores ?? []).filter((score) => {
            if (tierFilter !== 'all' && score.tier !== tierFilter) {
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
    }, [run, search, tierFilter]);

    const handleRun = () => {
        runAssessment({
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            // The store's own notify() already re-renders `runs` with the new
            // run in front; just point the view at it.
            onSuccess: (newRun) => setActiveHashid(newRun.hashid),
        });
    };

    const handleDelete = () => {
        if (!run || !window.confirm('Delete this assessment run?')) {
            return;
        }

        // If the deleted run was active, `run` falls back to `runs[0]` on its
        // own once the store notifies — no need to manage that here.
        deleteRun(run.hashid, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
        });
    };

    return (
        <>
            <Head title="Attrition Risk" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                            <TrendingDown className="size-5 text-[#0ABFBF]" />
                            Attrition Risk
                        </h1>
                        <p className="max-w-2xl text-sm text-muted-foreground">
                            A simulated flight-risk score for a demo roster —
                            from overtime, tenure, promotion cadence, pay and
                            recent training — previewing how HR could step in
                            with retention before a resignation, not after.
                        </p>
                    </div>
                    <Button size="sm" onClick={handleRun} disabled={processing}>
                        {processing ? (
                            <Spinner />
                        ) : (
                            <Sparkles className="size-4" />
                        )}
                        {processing ? 'Assessing…' : 'Run assessment'}
                    </Button>
                </div>

                <DemoBanner />

                {!run ? (
                    <EmptyState processing={processing} onRun={handleRun} />
                ) : (
                    <>
                        <RiskStatsCards run={run} />

                        {/* Run meta + history */}
                        <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                            <span>
                                Last assessed {formatRelative(run.created_at)}
                                {run.generated_by
                                    ? ` by ${run.generated_by}`
                                    : ''}
                            </span>
                            <div className="flex items-center gap-2">
                                {runSummaries.length > 1 && (
                                    <div className="flex items-center gap-1.5">
                                        <History className="size-3.5" />
                                        <Select
                                            value={run.hashid}
                                            onValueChange={setActiveHashid}
                                        >
                                            <SelectTrigger className="h-8 w-56 text-xs">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {runSummaries.map((r) => (
                                                    <SelectItem
                                                        key={r.hashid}
                                                        value={r.hashid}
                                                    >
                                                        {formatRelative(
                                                            r.created_at,
                                                        )}{' '}
                                                        · {r.employees_scored}{' '}
                                                        scored · {r.high_count}{' '}
                                                        high
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}
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
                                value={tierFilter}
                                onValueChange={(v) =>
                                    setTierFilter(v as RiskTier | 'all')
                                }
                            >
                                <SelectTrigger className="sm:w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {TIER_FILTERS.map((t) => (
                                        <SelectItem
                                            key={t.value}
                                            value={t.value}
                                        >
                                            {t.label}
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
                                        <RiskRow
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

function RiskRow({
    rank,
    score,
    onOpen,
}: {
    rank: number;
    score: RiskScore;
    onOpen: () => void;
}) {
    const overtime = score.features.OverTime === 'Yes';
    const subtitle = overtime
        ? 'Works overtime'
        : `${formatConfidence(score.confidence)} confidence`;

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
                        {` · ${subtitle}`}
                    </p>
                </div>
                <div className="flex w-12 flex-col items-end">
                    <span
                        className={cn(
                            'text-sm font-semibold tabular-nums',
                            scoreTone(score.score),
                        )}
                    >
                        {formatScore(score.score)}
                    </span>
                    <span className="text-[10px] tracking-wide text-muted-foreground uppercase">
                        / 100
                    </span>
                </div>
                <RiskBadge tier={score.tier} />
                <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
            </button>
        </li>
    );
}

function EmptyState({
    processing,
    onRun,
}: {
    processing: boolean;
    onRun: () => void;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-12 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <TrendingDown className="size-6" />
            </span>
            <p className="text-sm font-medium">No assessment yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Run an assessment to generate a simulated flight-risk score for
                the demo roster.
            </p>
            <Button className="mt-1" onClick={onRun} disabled={processing}>
                {processing ? <Spinner /> : <Sparkles className="size-4" />}
                {processing ? 'Assessing…' : 'Run first assessment'}
            </Button>
        </div>
    );
}

AttritionRisk.layout = {
    breadcrumbs: [
        { title: 'Analytics', href: '/analytics/attrition' },
        {
            title: 'Attrition Risk',
            href: '/analytics/attrition',
        },
    ],
};
