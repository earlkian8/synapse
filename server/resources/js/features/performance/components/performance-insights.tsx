import {
    AlertCircle,
    Check,
    ClipboardList,
    Lightbulb,
    RefreshCw,
    Sparkles,
    Target,
    TriangleAlert,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { fetchPerformanceInsights } from '../api';
import type { PerformanceInsight, PerformanceInsightResult } from '../types';

/**
 * On-demand LLM decision support for one employee's evaluation: the model reads
 * the scorecard, remarks and rating history and returns a grounded managerial
 * read — strengths, development areas, concrete coaching actions, goals for next
 * cycle, and a recommendation. Persisted server-side so it reopens instantly.
 */
export function PerformanceInsights({
    hashid,
    saved,
}: {
    hashid: string;
    saved: PerformanceInsight | null;
}) {
    const [result, setResult] = useState<PerformanceInsightResult | null>(
        saved,
    );
    const [loading, setLoading] = useState(false);

    const generate = useCallback(async () => {
        setLoading(true);

        try {
            setResult(await fetchPerformanceInsights(hashid));
        } finally {
            setLoading(false);
        }
    }, [hashid]);

    return (
        <section className="overflow-hidden rounded-xl border border-[#0ABFBF]/30 bg-gradient-to-br from-[#0ABFBF]/[0.07] to-transparent">
            <header className="flex items-center justify-between gap-3 border-b border-[#0ABFBF]/15 px-4 py-2.5">
                <h3 className="flex items-center gap-2 text-sm font-semibold tracking-tight">
                    <Sparkles className="size-4 text-[#0ABFBF]" />
                    AI Coaching Insights
                    <span className="rounded-full bg-[#0ABFBF]/15 px-1.5 py-0.5 text-[9px] font-semibold tracking-wider text-[#0a8f8f] uppercase dark:text-[#0ABFBF]">
                        LLM
                    </span>
                </h3>
                {result?.available && (
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
                ) : result === null ? (
                    <Intro onGenerate={generate} />
                ) : result.available ? (
                    <Body insight={result} />
                ) : (
                    <Unavailable
                        reason={result.reason}
                        retryable={result.retryable}
                        onRetry={generate}
                    />
                )}
            </div>
        </section>
    );
}

function Intro({ onGenerate }: { onGenerate: () => void }) {
    return (
        <div className="flex flex-col items-start gap-3">
            <p className="text-sm text-muted-foreground">
                Let AI read this scorecard, the remarks and the employee's
                rating history — and return strengths, development areas,
                coaching actions, and goals for the next cycle.
            </p>
            <Button size="sm" onClick={onGenerate}>
                <Sparkles className="size-4" />
                Generate insights
            </Button>
        </div>
    );
}

function Loading() {
    return (
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Spinner className="size-4 text-[#0ABFBF]" />
            Analysing the scorecard and history…
        </div>
    );
}

function Body({ insight }: { insight: PerformanceInsight }) {
    return (
        <div className="flex flex-col gap-4">
            {insight.headline && (
                <p className="text-base leading-snug font-semibold tracking-tight">
                    {insight.headline}
                </p>
            )}

            {insight.summary && (
                <p className="text-sm leading-relaxed text-foreground/90">
                    {insight.summary}
                </p>
            )}

            {insight.strengths.length > 0 && (
                <List
                    label="Strengths"
                    icon={<Check className="size-3.5 text-emerald-500" />}
                    items={insight.strengths}
                    marker={
                        <Check className="mt-0.5 size-3.5 shrink-0 text-emerald-500" />
                    }
                />
            )}

            {insight.development_areas.length > 0 && (
                <List
                    label="Development areas"
                    icon={<TriangleAlert className="size-3.5 text-amber-500" />}
                    items={insight.development_areas}
                    marker={
                        <TriangleAlert className="mt-0.5 size-3.5 shrink-0 text-amber-500" />
                    }
                />
            )}

            {insight.coaching_actions.length > 0 && (
                <List
                    label="Coaching actions"
                    icon={<ClipboardList className="size-3.5 text-[#0ABFBF]" />}
                    items={insight.coaching_actions}
                    marker={
                        <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-[#0ABFBF]" />
                    }
                />
            )}

            {insight.suggested_goals.length > 0 && (
                <List
                    label="Goals for next period"
                    icon={<Target className="size-3.5 text-violet-500" />}
                    items={insight.suggested_goals}
                    marker={
                        <Target className="mt-0.5 size-3.5 shrink-0 text-violet-500" />
                    }
                />
            )}

            {insight.recommendation && (
                <div className="flex gap-2 rounded-lg bg-[#0ABFBF]/[0.08] p-3 text-sm">
                    <Lightbulb className="mt-0.5 size-4 shrink-0 text-amber-500" />
                    <span className="text-foreground/90">
                        {insight.recommendation}
                    </span>
                </div>
            )}

            <p className="text-[11px] text-muted-foreground/70">
                AI-generated from this evaluation{' '}
                {formatStamp(insight.generated_at)} — verify before acting on
                it.
            </p>
        </div>
    );
}

function List({
    label,
    icon,
    items,
    marker,
}: {
    label: string;
    icon: React.ReactNode;
    items: string[];
    marker: React.ReactNode;
}) {
    return (
        <div>
            <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {icon}
                {label}
            </p>
            <ul className="flex flex-col gap-1.5">
                {items.map((item, index) => (
                    <li key={index} className="flex gap-2 text-sm">
                        {marker}
                        <span className="text-foreground/90">{item}</span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function Unavailable({
    reason,
    retryable,
    onRetry,
}: {
    reason: string;
    retryable: boolean;
    onRetry: () => void;
}) {
    return (
        <div className="flex flex-col items-start gap-3">
            <p className="flex items-start gap-2 text-sm text-muted-foreground">
                <AlertCircle className="mt-0.5 size-4 shrink-0 text-amber-500" />
                {reason}
            </p>
            {retryable && (
                <Button variant="outline" size="sm" onClick={onRetry}>
                    <RefreshCw className="size-4" />
                    Try again
                </Button>
            )}
        </div>
    );
}

/** A short, human "generated at" stamp; falls back to nothing on a bad date. */
function formatStamp(iso: string): string {
    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? ''
        : `· ${date.toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })}`;
}
