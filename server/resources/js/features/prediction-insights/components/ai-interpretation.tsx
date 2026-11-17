import { AlertCircle, Check, RefreshCw, Sparkles, Info, X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { requestInterpretation } from '../api';
import { formatPoints } from '../lib';
import type {
    Interpretation,
    InterpretationReason,
    InterpretationResult,
} from '../types';

/**
 * Readings written during this visit, by endpoint, so reopening a dialog shows the
 * one just written rather than the page's older props. Written only from the
 * request's resolution, never during render.
 */
const written = new Map<string, InterpretationResult>();

/**
 * A plain-language reading an LLM writes on request (ADR 0106) — of one person's
 * prediction, or of a model check — set as an annotation beside the numbers it
 * explains. Everything but the sentences comes from the stored result: which
 * reasons there are, their points, whether a check was met.
 *
 * Mount it with a `key` per result, so another person or model starts fresh.
 */
export function AiInterpretation({
    url,
    saved,
    title = 'In plain words',
    intro,
    action,
    pending,
    upIsGood,
    contextLabel = 'Beside the record',
    checksLabel = 'Before you act',
}: {
    /** Where the reading is written. */
    url: string;
    /** The reading saved earlier, if this viewer may see it. */
    saved: Interpretation | null;
    title?: string;
    /** What asking does, before anything is written. */
    intro: ReactNode;
    /** The button that asks: "Explain this score". */
    action: string;
    /** While it is being written: "Reading the breakdown…". */
    pending: string;
    /** Whether a reason that raises the score is good news (readiness) or not (risk). */
    upIsGood: boolean;
    contextLabel?: string;
    checksLabel?: string;
}) {
    const [result, setResult] = useState<InterpretationResult | null>(
        () => written.get(url) ?? saved,
    );
    const [loading, setLoading] = useState(false);

    const ask = async () => {
        setLoading(true);

        try {
            const next = await requestInterpretation(url);

            if (next.available) {
                written.set(url, next);
            }

            setResult(next);
        } finally {
            setLoading(false);
        }
    };

    return (
        <section
            aria-label={title}
            aria-busy={loading}
            className="flex flex-col gap-3 border-l-2 border-[#0ABFBF]/70 pl-4"
        >
            <header className="flex items-center justify-between gap-3">
                <h3 className="flex items-center gap-1.5 text-sm font-semibold">
                    <Sparkles
                        className="size-4 text-teal-700 dark:text-[#0ABFBF]"
                        aria-hidden="true"
                    />
                    {title}
                </h3>
                {result?.available && !loading && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="h-7 px-2 text-xs text-muted-foreground"
                        onClick={ask}
                    >
                        <RefreshCw className="size-3.5" />
                        Write again
                    </Button>
                )}
            </header>

            {loading ? (
                <p
                    className="flex items-center gap-2 text-sm text-muted-foreground"
                    role="status"
                >
                    <Spinner className="size-4 text-[#0ABFBF]" />
                    {pending}
                </p>
            ) : result === null ? (
                <div className="flex flex-col items-start gap-2.5">
                    <p className="text-sm text-muted-foreground">{intro}</p>
                    <Button size="sm" variant="outline" onClick={ask}>
                        <Sparkles className="size-3.5" />
                        {action}
                    </Button>
                </div>
            ) : result.available ? (
                <Reading
                    reading={result}
                    upIsGood={upIsGood}
                    contextLabel={contextLabel}
                    checksLabel={checksLabel}
                />
            ) : (
                <div className="flex flex-col items-start gap-2.5">
                    <p className="flex items-start gap-2 text-sm text-muted-foreground">
                        <AlertCircle className="mt-0.5 size-4 shrink-0 text-amber-600" />
                        {result.reason}
                    </p>
                    {result.retryable && (
                        <Button variant="outline" size="sm" onClick={ask}>
                            <RefreshCw className="size-3.5" />
                            Try again
                        </Button>
                    )}
                </div>
            )}
        </section>
    );
}

function Reading({
    reading,
    upIsGood,
    contextLabel,
    checksLabel,
}: {
    reading: Interpretation;
    upIsGood: boolean;
    contextLabel: string;
    checksLabel: string;
}) {
    const checks = reading.checks ?? [];

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1.5">
                {reading.headline && (
                    <p className="text-[15px] leading-snug font-medium text-pretty">
                        {reading.headline}
                    </p>
                )}
                <p className="text-sm leading-relaxed text-foreground/85">
                    {reading.summary}
                </p>
            </div>

            {reading.reasons.length > 0 && (
                <Part label="Why">
                    <ul className="flex flex-col divide-y divide-border/70">
                        {reading.reasons.map((reason) => (
                            <ReasonRow
                                key={reason.key}
                                reason={reason}
                                upIsGood={upIsGood}
                            />
                        ))}
                    </ul>
                </Part>
            )}

            {reading.context && (
                <Part label={contextLabel}>
                    <p className="text-sm leading-relaxed text-foreground/85">
                        {reading.context}
                    </p>
                </Part>
            )}

            {checks.length > 0 && (
                <Part label={checksLabel}>
                    <Lines
                        items={checks}
                        marker={
                            <Info className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                        }
                    />
                </Part>
            )}

            {reading.actions.length > 0 && (
                <Part label="What to do">
                    <Lines
                        items={reading.actions}
                        marker={
                            <Check className="mt-0.5 size-3.5 shrink-0 text-teal-700 dark:text-[#0ABFBF]" />
                        }
                    />
                </Part>
            )}

            <p className="text-xs text-muted-foreground">
                Written by AI{stamp(reading.generated_at)}. AI can make
                mistakes. Check important details before acting.
            </p>
        </div>
    );
}

function ReasonRow({
    reason,
    upIsGood,
}: {
    reason: InterpretationReason;
    upIsGood: boolean;
}) {
    const good =
        reason.effect === null
            ? null
            : (reason.effect === 'raises') === upIsGood;

    return (
        <li className="flex flex-col gap-0.5 py-2 first:pt-0 last:pb-0">
            <div className="flex items-baseline justify-between gap-3">
                <span className="text-sm font-medium">{reason.label}</span>
                {reason.points !== null ? (
                    <span
                        className={cn(
                            'shrink-0 text-sm font-semibold tabular-nums',
                            good
                                ? 'text-teal-700 dark:text-teal-400'
                                : 'text-rose-700 dark:text-rose-400',
                        )}
                    >
                        {formatPoints(reason.points, 1)}
                        <span className="sr-only">
                            {' '}
                            points, {reason.effect}{' '}
                            {good ? '(in their favour)' : '(against)'}
                        </span>
                    </span>
                ) : reason.met !== undefined && reason.met !== null ? (
                    <span
                        className={cn(
                            'inline-flex shrink-0 items-center gap-1 text-xs font-medium',
                            reason.met
                                ? 'text-teal-700 dark:text-teal-400'
                                : 'text-amber-700 dark:text-amber-400',
                        )}
                    >
                        {reason.met ? (
                            <Check className="size-3.5" />
                        ) : (
                            <X className="size-3.5" />
                        )}
                        {reason.met ? 'Met' : 'Not met'}
                    </span>
                ) : null}
            </div>
            <p className="text-sm leading-relaxed text-muted-foreground">
                {reason.text}
            </p>
        </li>
    );
}

function Part({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1.5">
            <h4 className="text-xs font-semibold text-foreground/70">
                {label}
            </h4>
            {children}
        </div>
    );
}

function Lines({ items, marker }: { items: string[]; marker: ReactNode }) {
    return (
        <ul className="flex flex-col gap-1.5">
            {items.map((item) => (
                <li key={item} className="flex gap-2 text-sm">
                    {marker}
                    <span className="text-foreground/85">{item}</span>
                </li>
            ))}
        </ul>
    );
}

/** " · Nov 17, 3:04 PM", or nothing on a bad date. */
function stamp(iso: string): string {
    const date = new Date(iso);

    return Number.isNaN(date.getTime())
        ? ''
        : ` · ${date.toLocaleString(undefined, {
              month: 'short',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })}`;
}
