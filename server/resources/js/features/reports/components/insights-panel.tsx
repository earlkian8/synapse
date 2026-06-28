import {
    Activity,
    AlertCircle,
    HelpCircle,
    Lightbulb,
    RefreshCw,
    Sparkles,
    TrendingUp,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { fetchInsights } from '../api';
import type { Insights } from '../types';

/**
 * The decision-support centrepiece: on demand, the LLM reads this report's figures,
 * charts and ML signals and answers the questions a manager actually has — what's
 * happening, what changed, why, and what to do next.
 *
 * Mounted with a key of `report + filters`, so switching report or changing a filter
 * remounts it fresh and any stale narrative is dropped (the figures it described no
 * longer match the screen).
 */
export function InsightsPanel({
    reportKey,
    applied,
    aiEnabled,
}: {
    reportKey: string;
    applied: Record<string, string>;
    aiEnabled: boolean;
}) {
    const [insights, setInsights] = useState<Insights | null>(null);
    const [loading, setLoading] = useState(false);

    const generate = useCallback(async () => {
        setLoading(true);

        try {
            setInsights(await fetchInsights(reportKey, applied));
        } finally {
            setLoading(false);
        }
    }, [reportKey, applied]);

    return (
        <section className="report-no-print overflow-hidden rounded-xl border border-[#0ABFBF]/30 bg-gradient-to-br from-[#0ABFBF]/[0.07] to-transparent shadow-sm">
            <header className="flex items-center justify-between gap-3 border-b border-[#0ABFBF]/15 px-4 py-2.5">
                <h2 className="flex items-center gap-2 text-sm font-semibold tracking-tight">
                    <Sparkles className="size-4 text-[#0ABFBF]" />
                    AI Insights
                    <span className="rounded-full bg-[#0ABFBF]/15 px-1.5 py-0.5 text-[9px] font-semibold tracking-wider text-[#0a8f8f] uppercase dark:text-[#0ABFBF]">
                        Decision support
                    </span>
                </h2>
                {insights?.available && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 text-xs text-muted-foreground"
                        onClick={generate}
                        disabled={loading}
                    >
                        <RefreshCw className="size-3.5" />
                        Regenerate
                    </Button>
                )}
            </header>

            <div className="p-4">
                {loading ? (
                    <Loading />
                ) : insights === null ? (
                    <Intro aiEnabled={aiEnabled} onGenerate={generate} />
                ) : insights.available ? (
                    <Narrative insights={insights} />
                ) : (
                    <Unavailable insights={insights} onRetry={generate} />
                )}
            </div>
        </section>
    );
}

function Intro({
    aiEnabled,
    onGenerate,
}: {
    aiEnabled: boolean;
    onGenerate: () => void;
}) {
    return (
        <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="max-w-xl text-sm text-muted-foreground">
                Read this report for you — what's happening, what changed, why,
                and what to do about it — grounded in its totals, charts and
                model signals.
            </p>
            {aiEnabled ? (
                <Button size="sm" className="shrink-0" onClick={onGenerate}>
                    <Sparkles className="size-4" />
                    Generate insights
                </Button>
            ) : (
                <p className="shrink-0 rounded-md bg-muted px-3 py-1.5 text-xs text-muted-foreground">
                    AI insights aren't configured.
                </p>
            )}
        </div>
    );
}

function Loading() {
    return (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner className="size-4 text-[#0ABFBF]" />
            Reading the report…
        </div>
    );
}

function Narrative({ insights }: { insights: Insights }) {
    return (
        <div className="flex flex-col gap-4">
            {insights.headline && (
                <p className="text-base leading-snug font-semibold tracking-tight">
                    {insights.headline}
                </p>
            )}

            <div className="grid gap-4 sm:grid-cols-3">
                <Block
                    icon={<Activity className="size-4" />}
                    label="What's happening"
                    text={insights.whats_happening}
                />
                <Block
                    icon={<TrendingUp className="size-4" />}
                    label="What changed"
                    text={insights.what_happened}
                />
                <Block
                    icon={<HelpCircle className="size-4" />}
                    label="Why"
                    text={insights.why}
                />
            </div>

            {insights.recommendations &&
                insights.recommendations.length > 0 && (
                    <div className="rounded-lg border border-sidebar-border/70 bg-card/60 p-3 dark:border-sidebar-border">
                        <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            <Lightbulb className="size-3.5 text-amber-500" />
                            Recommended actions
                        </p>
                        <ul className="flex flex-col gap-1.5">
                            {insights.recommendations.map((item, index) => (
                                <li key={index} className="flex gap-2 text-sm">
                                    <span className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-[#0ABFBF]/15 text-[10px] font-semibold text-[#0a8f8f] tabular-nums dark:text-[#0ABFBF]">
                                        {index + 1}
                                    </span>
                                    {item}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

            <p className="text-[11px] text-muted-foreground/70">
                AI-generated from this report's data — verify before acting on
                it.
            </p>
        </div>
    );
}

function Block({
    icon,
    label,
    text,
}: {
    icon: ReactNode;
    label: string;
    text?: string;
}) {
    if (!text) {
        return null;
    }

    return (
        <div className="flex flex-col gap-1.5">
            <p className="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-[#0a8f8f] uppercase dark:text-[#0ABFBF]">
                <span className="text-[#0ABFBF]">{icon}</span>
                {label}
            </p>
            <p className="text-sm leading-relaxed text-foreground/90">{text}</p>
        </div>
    );
}

function Unavailable({
    insights,
    onRetry,
}: {
    insights: Insights;
    onRetry: () => void;
}) {
    return (
        <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="flex items-start gap-2 text-sm text-muted-foreground">
                <AlertCircle className="mt-0.5 size-4 shrink-0 text-amber-500" />
                {insights.reason ?? 'Insights are unavailable right now.'}
            </p>
            {insights.retryable && (
                <Button
                    variant="outline"
                    size="sm"
                    className="shrink-0"
                    onClick={onRetry}
                >
                    <RefreshCw className="size-4" />
                    Try again
                </Button>
            )}
        </div>
    );
}
