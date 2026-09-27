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
import type { LucideIcon } from 'lucide-react';
import { StatTiles } from '@/components/data-table';
import type { StatTile } from '@/components/data-table';
import { cn } from '@/lib/utils';
import { RECOMMENDATION_STYLES } from '../constants';
import type {
    OverallInsight,
    PipelineInsightsData,
    PipelineStage,
    StageInsight,
} from '../types';

type Tone = 'positive' | 'neutral' | 'caution';

type Tile = Omit<StatTile, 'key' | 'value'> & { value: string | number };

type Signal = {
    tone: Tone;
    icon: LucideIcon;
    title: string;
    detail: string;
};

type View = {
    heading: string;
    tiles: Tile[];
    signal: Signal;
};

const SIGNAL_ICON_STYLES: Record<Tone, string> = {
    positive: 'text-emerald-600 dark:text-emerald-400',
    neutral: 'text-sky-600 dark:text-sky-400',
    caution: 'text-amber-600 dark:text-amber-400',
};

const fitValue = (value: number | null) => (value === null ? '—' : `${value}`);

/** Build the tiles + decision signal for the whole-pipeline (All) view. */
function overallView(o: OverallInsight): View {
    const tiles: Tile[] = [
        { label: 'Active', value: o.active, icon: Users, accent: 'teal' },
        {
            label: 'Avg fit',
            value: fitValue(o.avg_fit),
            icon: TrendingUp,
            accent: 'sky',
        },
        {
            label: 'Strong fits',
            value: o.strong,
            icon: Star,
            accent: 'emerald',
        },
        {
            label: 'Ready to advance',
            value: o.ready,
            icon: CheckCircle2,
            accent: 'violet',
        },
        {
            label: 'Stalled 14d+',
            value: o.stalled,
            icon: Clock,
            accent: 'amber',
        },
        {
            label: 'Hire rate',
            value: o.conversion === null ? '—' : `${o.conversion}%`,
            icon: UserCheck,
            accent: 'rose',
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
                ? `${o.top.name} leads at ${o.top.fit}% fit${o.top.stage ? ` in ${o.top.stage}` : ''}. Move standouts forward to keep momentum.`
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

/** Build the tiles + decision signal for a single stage. */
function stageView(stage: PipelineStage, s: StageInsight): View {
    const label = stage.name;
    const terminal = stage.kind !== 'open';

    const tiles: Tile[] = [
        {
            label: `In ${label}`,
            value: s.count,
            icon: Users,
            accent: 'teal',
        },
        {
            label: 'Avg fit',
            value: fitValue(s.avg_fit),
            icon: TrendingUp,
            accent: 'sky',
        },
        {
            label: 'Strong fits',
            value: s.strong,
            icon: Star,
            accent: 'emerald',
        },
    ];

    if (!terminal) {
        tiles.push(
            {
                label: s.next_stage ? `Ready → ${s.next_stage}` : 'Ready',
                value: s.ready,
                icon: CheckCircle2,
                accent: 'violet',
            },
            {
                label: 'Stalled 14d+',
                value: s.stalled,
                icon: Clock,
                accent: 'amber',
            },
        );
    } else if (s.top) {
        tiles.push({
            label: 'Top fit',
            value: `${s.top.fit}`,
            icon: Trophy,
            accent: stage.kind === 'won' ? 'emerald' : 'slate',
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
    } else if (stage.kind === 'won') {
        signal = {
            tone: 'positive',
            icon: UserCheck,
            title: `${s.count} candidate${s.count === 1 ? '' : 's'} hired`,
            detail: 'Converted into employees and copied into the 201 file.',
        };
    } else if (stage.kind === 'lost') {
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
            title: `${s.ready} ready to move${s.next_stage ? ` to ${s.next_stage}` : ' forward'}`,
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
 * The pipeline's decision support: the focused stage's counts as the page's
 * stat tiles, then one line on what to do next — re-derived from the fit
 * scores whenever the recruiter focuses another stage.
 */
export function PipelineInsights({
    insights,
    stage,
}: {
    insights: PipelineInsightsData;
    /** The focused stage, or 'all' for the whole pipeline. */
    stage: PipelineStage | 'all';
}) {
    const view =
        stage === 'all'
            ? overallView(insights.overall)
            : stageView(stage, insights.stages[stage.id]);

    const SignalIcon = view.signal.icon;

    return (
        <section
            aria-label={`Decision support: ${view.heading}`}
            className="flex flex-col gap-2.5"
        >
            <StatTiles
                tiles={view.tiles.map((tile) => ({
                    ...tile,
                    key: tile.label,
                    value:
                        typeof tile.value === 'number'
                            ? tile.value.toLocaleString()
                            : tile.value,
                }))}
            />

            <div
                className={cn(
                    'flex flex-col gap-1 rounded-xl border px-4 py-2.5 sm:flex-row sm:items-center sm:gap-3',
                    RECOMMENDATION_STYLES[view.signal.tone],
                )}
            >
                <span className="flex shrink-0 items-center gap-1.5 text-sm font-semibold">
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
                <span className="inline-flex shrink-0 items-center gap-1 text-[11px] font-medium text-muted-foreground sm:ml-auto">
                    <Sparkles className="size-3" />
                    {view.heading}
                    {view.signal.tone === 'positive' && (
                        <>
                            {' · '}
                            <span className="text-emerald-700 dark:text-emerald-300">
                                Recommended action
                            </span>
                            <ArrowRight className="size-3 text-emerald-700 dark:text-emerald-300" />
                        </>
                    )}
                </span>
            </div>
        </section>
    );
}
