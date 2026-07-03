import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    Clock,
    Sparkles,
    Star,
    TrendingUp,
    Trophy,
    UserCheck,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { cn } from '@/lib/utils';
import { RECOMMENDATION_STYLES, STAGE_LABELS } from '../constants';
import type {
    OverallInsight,
    PipelineInsightsData,
    StageFilter,
    StageInsight,
} from '../types';

type Tone = 'positive' | 'neutral' | 'caution';

type Tile = {
    label: string;
    value: string | number;
    icon: ComponentType<{ className?: string }>;
    accent: string;
    hint?: string;
};

type Signal = {
    tone: Tone;
    icon: ComponentType<{ className?: string }>;
    title: string;
    detail: string;
};

type View = {
    heading: string;
    tiles: Tile[];
    signal: Signal;
};

const ACCENTS = {
    teal: 'text-[#0ABFBF] bg-[#0ABFBF]/10',
    sky: 'text-sky-600 bg-sky-500/10',
    emerald: 'text-emerald-600 bg-emerald-500/10',
    violet: 'text-violet-600 bg-violet-500/10',
    amber: 'text-amber-600 bg-amber-500/10',
    rose: 'text-rose-600 bg-rose-500/10',
    slate: 'text-slate-600 bg-slate-500/10',
} as const;

const SIGNAL_ICON_STYLES: Record<Tone, string> = {
    positive: 'text-emerald-600 dark:text-emerald-400',
    neutral: 'text-sky-600 dark:text-sky-400',
    caution: 'text-amber-600 dark:text-amber-400',
};

const fit = (value: number | null) => (value === null ? '—' : `${value}`);

/** Build the tiles + decision signal for the whole-pipeline (All) view. */
function overallView(o: OverallInsight): View {
    const tiles: Tile[] = [
        { label: 'Active', value: o.active, icon: Users, accent: ACCENTS.teal },
        {
            label: 'Avg fit',
            value: fit(o.avg_fit),
            icon: TrendingUp,
            accent: ACCENTS.sky,
        },
        {
            label: 'Strong fits',
            value: o.strong,
            icon: Star,
            accent: ACCENTS.emerald,
        },
        {
            label: 'Ready to advance',
            value: o.ready,
            icon: CheckCircle2,
            accent: ACCENTS.violet,
        },
        {
            label: 'Stalled 14d+',
            value: o.stalled,
            icon: Clock,
            accent: ACCENTS.amber,
        },
        {
            label: 'Hire rate',
            value: o.conversion === null ? '—' : `${o.conversion}%`,
            icon: UserCheck,
            accent: ACCENTS.rose,
            hint: 'Hired vs. decided candidates',
        },
    ];

    let signal: Signal;

    if (o.active === 0) {
        signal = {
            tone: 'neutral',
            icon: Users,
            title: 'No active candidates',
            detail: 'Add candidates to start building this pipeline.',
        };
    } else if (o.ready > 0) {
        signal = {
            tone: 'positive',
            icon: CheckCircle2,
            title: `${o.ready} candidate${o.ready === 1 ? '' : 's'} ready to advance`,
            detail: o.top
                ? `${o.top.name} leads at ${o.top.fit}% fit${o.top.stage ? ` in ${STAGE_LABELS[o.top.stage]}` : ''}. Move standouts forward to keep momentum.`
                : 'Move the strongest candidates to their next stage.',
        };
    } else if (o.stalled > 0) {
        signal = {
            tone: 'caution',
            icon: AlertTriangle,
            title: `${o.stalled} candidate${o.stalled === 1 ? '' : 's'} have stalled`,
            detail: 'These have sat in an open stage for 14+ days — review or reject to keep the pipeline clean.',
        };
    } else {
        signal = {
            tone: 'neutral',
            icon: Sparkles,
            title: 'Pipeline looks healthy',
            detail: `${o.active} active candidate${o.active === 1 ? '' : 's'}${o.strong > 0 ? `, ${o.strong} a strong fit` : ''}. Keep ratings and interviews current for sharper ranking.`,
        };
    }

    return { heading: 'Pipeline overview', tiles, signal };
}

/** Build the tiles + decision signal for a single stage tab. */
function stageView(stage: Exclude<StageFilter, 'all'>, s: StageInsight): View {
    const label = STAGE_LABELS[stage];
    const nextLabel = s.next_stage ? STAGE_LABELS[s.next_stage] : null;
    const terminal = stage === 'hired' || stage === 'rejected';

    const tiles: Tile[] = [
        {
            label: `In ${label}`,
            value: s.count,
            icon: Users,
            accent: ACCENTS.teal,
        },
        {
            label: 'Avg fit',
            value: fit(s.avg_fit),
            icon: TrendingUp,
            accent: ACCENTS.sky,
        },
        {
            label: 'Strong fits',
            value: s.strong,
            icon: Star,
            accent: ACCENTS.emerald,
        },
    ];

    if (!terminal) {
        tiles.push(
            {
                label: nextLabel ? `Ready → ${nextLabel}` : 'Ready',
                value: s.ready,
                icon: CheckCircle2,
                accent: ACCENTS.violet,
            },
            {
                label: 'Stalled 14d+',
                value: s.stalled,
                icon: Clock,
                accent: ACCENTS.amber,
            },
        );
    } else if (s.top) {
        tiles.push({
            label: 'Top fit',
            value: `${s.top.fit}`,
            icon: Trophy,
            accent: stage === 'hired' ? ACCENTS.emerald : ACCENTS.slate,
        });
    }

    let signal: Signal;

    if (s.count === 0) {
        signal = {
            tone: 'neutral',
            icon: Users,
            title: `No candidates in ${label}`,
            detail: 'Nothing to action in this stage right now.',
        };
    } else if (stage === 'hired') {
        signal = {
            tone: 'positive',
            icon: UserCheck,
            title: `${s.count} candidate${s.count === 1 ? '' : 's'} hired`,
            detail: 'Converted into employees and copied into the 201 file.',
        };
    } else if (stage === 'rejected') {
        signal = {
            tone: 'neutral',
            icon: Users,
            title: `${s.count} candidate${s.count === 1 ? '' : 's'} rejected`,
            detail: 'Kept for reference and reporting. No further action.',
        };
    } else if (s.ready > 0) {
        signal = {
            tone: 'positive',
            icon: CheckCircle2,
            title: `${s.ready} ready to move${nextLabel ? ` to ${nextLabel}` : ' forward'}`,
            detail: s.top
                ? `${s.top.name} leads this stage at ${s.top.fit}% fit — a strong candidate to advance next.`
                : 'The scorer flags these as strong enough for the next step.',
        };
    } else if (s.stalled > 0) {
        signal = {
            tone: 'caution',
            icon: AlertTriangle,
            title: `${s.stalled} stalled in ${label}`,
            detail: 'Sitting 14+ days without progress — give them a decision to keep the pipeline moving.',
        };
    } else {
        signal = {
            tone: 'neutral',
            icon: Sparkles,
            title: `${s.count} candidate${s.count === 1 ? '' : 's'} in ${label}`,
            detail: s.top
                ? `${s.top.name} tops the stage at ${s.top.fit}% fit. Rate and interview to sharpen the ranking.`
                : 'Rate and interview candidates to sharpen their fit ranking.',
        };
    }

    return { heading: `${label} stage`, tiles, signal };
}

/**
 * The decision-support panel above the pipeline board — a compact strip of
 * contextual stats plus an ML-driven "what to do next" signal that re-derives
 * itself from the fit scores whenever the recruiter switches stage tabs.
 */
export function PipelineInsights({
    insights,
    stage,
}: {
    insights: PipelineInsightsData;
    stage: StageFilter;
}) {
    const view =
        stage === 'all'
            ? overallView(insights.overall)
            : stageView(stage, insights.stages[stage]);

    const SignalIcon = view.signal.icon;

    return (
        <section
            aria-label="Pipeline decision support"
            className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
        >
            <div className="flex items-center gap-2 border-b border-border/60 px-4 py-2.5">
                <span className="flex size-6 items-center justify-center rounded-md bg-[#0ABFBF]/10 text-[#0ABFBF]">
                    <Sparkles className="size-3.5" />
                </span>
                <span className="text-xs font-semibold tracking-tight">
                    Decision support
                </span>
                <span className="text-xs text-muted-foreground">
                    · {view.heading}
                </span>
            </div>

            <div className="grid gap-4 p-4 lg:grid-cols-[1fr_minmax(0,20rem)]">
                <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3">
                    {view.tiles.map((tile) => {
                        const Icon = tile.icon;

                        return (
                            <div
                                key={tile.label}
                                className="rounded-lg border border-border/60 bg-muted/30 p-3"
                                title={tile.hint}
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="truncate text-[11px] font-medium text-muted-foreground">
                                        {tile.label}
                                    </span>
                                    <span
                                        className={cn(
                                            'flex size-6 shrink-0 items-center justify-center rounded-md',
                                            tile.accent,
                                        )}
                                    >
                                        <Icon className="size-3.5" />
                                    </span>
                                </div>
                                <p className="mt-1.5 text-xl font-semibold tracking-tight tabular-nums">
                                    {tile.value}
                                </p>
                            </div>
                        );
                    })}
                </div>

                <div
                    className={cn(
                        'flex flex-col justify-center gap-1 rounded-lg border p-3.5',
                        RECOMMENDATION_STYLES[view.signal.tone],
                    )}
                >
                    <span className="flex items-center gap-1.5 text-sm font-semibold">
                        <SignalIcon
                            className={cn(
                                'size-4 shrink-0',
                                SIGNAL_ICON_STYLES[view.signal.tone],
                            )}
                        />
                        {view.signal.title}
                    </span>
                    <span className="text-xs leading-relaxed text-muted-foreground">
                        {view.signal.detail}
                    </span>
                    {view.signal.tone === 'positive' && (
                        <span className="mt-0.5 inline-flex items-center gap-1 text-[11px] font-medium text-emerald-700 dark:text-emerald-300">
                            Recommended action
                            <ArrowRight className="size-3" />
                        </span>
                    )}
                </div>
            </div>
        </section>
    );
}
