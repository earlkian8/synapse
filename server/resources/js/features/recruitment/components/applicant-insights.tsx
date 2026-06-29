import {
    AlertCircle,
    Check,
    FileText,
    Lightbulb,
    MessagesSquare,
    RefreshCw,
    Sparkles,
    TriangleAlert,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { fetchApplicantInsights } from '../api';
import type { ApplicantInsight, ApplicantInsightResult } from '../types';

/**
 * On-demand LLM decision support for one candidate: the model reads the
 * applicant's actual résumé and supporting documents (government IDs excluded)
 * alongside the role's criteria and the rule-based fit breakdown, and returns a
 * grounded read — strengths, concerns, what the documents reveal, sharp interview
 * questions, and a recommendation.
 *
 * Mounted with a key of the application id, so switching candidates remounts it
 * fresh and seeds from that candidate's last saved insight.
 */
export function ApplicantInsights({
    applicationId,
    saved,
}: {
    applicationId: number;
    saved: ApplicantInsight | null;
}) {
    const [result, setResult] = useState<ApplicantInsightResult | null>(saved);
    const [loading, setLoading] = useState(false);

    const generate = useCallback(async () => {
        setLoading(true);

        try {
            setResult(await fetchApplicantInsights(applicationId));
        } finally {
            setLoading(false);
        }
    }, [applicationId]);

    return (
        <section className="overflow-hidden rounded-xl border border-[#0ABFBF]/30 bg-gradient-to-br from-[#0ABFBF]/[0.07] to-transparent">
            <header className="flex items-center justify-between gap-3 border-b border-[#0ABFBF]/15 px-4 py-2.5">
                <h3 className="flex items-center gap-2 text-sm font-semibold tracking-tight">
                    <Sparkles className="size-4 text-[#0ABFBF]" />
                    AI Insights
                    <span className="rounded-full bg-[#0ABFBF]/15 px-1.5 py-0.5 text-[9px] font-semibold tracking-wider text-[#0a8f8f] uppercase dark:text-[#0ABFBF]">
                        Reads the résumé
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
                Let AI read this candidate's résumé and documents against the
                role — strengths, concerns, what the documents reveal, and
                interview questions to ask. Government IDs are never sent.
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
            Reading the résumé and documents…
        </div>
    );
}

function Body({ insight }: { insight: ApplicantInsight }) {
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

            {insight.document_insights && (
                <div className="rounded-lg border border-sidebar-border/70 bg-card/60 p-3 dark:border-sidebar-border">
                    <p className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        <FileText className="size-3.5 text-[#0ABFBF]" />
                        From the documents
                    </p>
                    <p className="text-sm leading-relaxed text-foreground/90">
                        {insight.document_insights}
                    </p>
                    {insight.documents_read.length > 0 && (
                        <div className="mt-2 flex flex-wrap gap-1.5">
                            {insight.documents_read.map((name) => (
                                <span
                                    key={name}
                                    className="rounded-full bg-[#0ABFBF]/10 px-2 py-0.5 text-[11px] font-medium text-[#0a8f8f] dark:text-[#0ABFBF]"
                                >
                                    {name}
                                </span>
                            ))}
                        </div>
                    )}
                </div>
            )}

            {insight.interview_questions.length > 0 && (
                <div>
                    <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                        <MessagesSquare className="size-3.5 text-[#0ABFBF]" />
                        Interview questions
                    </p>
                    <ul className="flex flex-col gap-1.5">
                        {insight.interview_questions.map((question, index) => (
                            <li key={index} className="flex gap-2 text-sm">
                                <span className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full bg-[#0ABFBF]/15 text-[10px] font-semibold text-[#0a8f8f] tabular-nums dark:text-[#0ABFBF]">
                                    {index + 1}
                                </span>
                                {question}
                            </li>
                        ))}
                    </ul>
                </div>
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
                AI-generated from the candidate's documents{' '}
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
