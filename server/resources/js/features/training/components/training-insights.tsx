import {
    AlertCircle,
    Check,
    ClipboardList,
    RefreshCw,
    Sparkles,
    TriangleAlert,
    UserRoundSearch,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { fetchTrainingInsights } from '../api';
import type { TrainingInsight, TrainingInsightResult } from '../types';

/**
 * On-demand LLM decision support for a training program: the model reads the
 * roster outcomes, completion rate and scores and returns a grounded read of the
 * program's effectiveness — what's working, concerns, recommendations, and who to
 * follow up with. Persisted server-side so it reopens instantly.
 */
export function TrainingInsightsPanel({
    hashid,
    saved,
}: {
    hashid: string;
    saved: TrainingInsight | null;
}) {
    const [result, setResult] = useState<TrainingInsightResult | null>(saved);
    const [loading, setLoading] = useState(false);

    const generate = useCallback(async () => {
        setLoading(true);

        try {
            setResult(await fetchTrainingInsights(hashid));
        } finally {
            setLoading(false);
        }
    }, [hashid]);

    return (
        <section className="overflow-hidden rounded-xl border border-[#0ABFBF]/30 bg-gradient-to-br from-[#0ABFBF]/[0.07] to-transparent">
            <header className="flex items-center justify-between gap-3 border-b border-[#0ABFBF]/15 px-4 py-2.5">
                <h3 className="flex items-center gap-2 text-sm font-semibold tracking-tight">
                    <Sparkles className="size-4 text-[#0ABFBF]" />
                    AI Effectiveness Insights
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
                Let AI read this program's completion rate, scores and dropouts —
                and return what's working, the concerns, recommendations, and who
                to follow up with.
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
            Analysing the roster and outcomes…
        </div>
    );
}

function Body({ insight }: { insight: TrainingInsight }) {
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

            {insight.whats_working.length > 0 && (
                <List
                    label="What's working"
                    icon={<Check className="size-3.5 text-emerald-500" />}
                    items={insight.whats_working}
                    marker={
                        <Check className="mt-0.5 size-3.5 shrink-0 text-emerald-500" />
                    }
                />
            )}

            {insight.concerns.length > 0 && (
                <List
                    label="Concerns"
                    icon={<TriangleAlert className="size-3.5 text-amber-500" />}
                    items={insight.concerns}
                    marker={
                        <TriangleAlert className="mt-0.5 size-3.5 shrink-0 text-amber-500" />
                    }
                />
            )}

            {insight.recommendations.length > 0 && (
                <List
                    label="Recommendations"
                    icon={<ClipboardList className="size-3.5 text-[#0ABFBF]" />}
                    items={insight.recommendations}
                    marker={
                        <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-[#0ABFBF]" />
                    }
                />
            )}

            {insight.follow_up.length > 0 && (
                <List
                    label="Follow up with"
                    icon={
                        <UserRoundSearch className="size-3.5 text-violet-500" />
                    }
                    items={insight.follow_up}
                    marker={
                        <UserRoundSearch className="mt-0.5 size-3.5 shrink-0 text-violet-500" />
                    }
                />
            )}

            <p className="text-[11px] text-muted-foreground/70">
                AI-generated from this roster{' '}
                {formatStamp(insight.generated_at)} — verify before acting on it.
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
