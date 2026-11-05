import { Info } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatShare } from '@/features/prediction-insights/lib';
import { cn } from '@/lib/utils';
import { inputLabel } from '../explain';
import type { RiskFactor, RiskReference } from '../types';

/** Fewer people than this in a band and its rate is flagged as thin. */
const FEW = 10;

/**
 * The evidence behind each reason: in the data the model learned from, the share of
 * people who left at each answer to that question, with this employee's answer
 * picked out and the overall rate as a line. Plain counts, not model output — so
 * when the model reads an answer against the grain of its own row (because of how it
 * combines with the person's other answers), the page says so instead of hiding it.
 */
export function SurveyEvidence({
    factors,
    evidence,
}: {
    factors: RiskFactor[];
    evidence: NonNullable<RiskReference['evidence']>;
}) {
    // A behavioural indicator's levels sit beside the inputs' bands (ADR 0101).
    const bandsOf = (feature: string) =>
        evidence.features[feature] ?? evidence.indicators?.[feature];
    const shown = factors
        .filter((f) => f.evidence && f.band && bandsOf(f.feature))
        .slice(0, 3);

    if (shown.length === 0) {
        return null;
    }

    const noun = evidence.source === 'survey' ? 'surveyed' : 'in your records';

    return (
        <ul className="flex flex-col divide-y divide-border rounded-xl border border-sidebar-border/60 dark:border-sidebar-border">
            {shown.map((factor) => (
                <EvidenceRow
                    key={factor.feature}
                    factor={factor}
                    bands={bandsOf(factor.feature)!}
                    overall={evidence.overall_rate}
                    noun={noun}
                />
            ))}
        </ul>
    );
}

function EvidenceRow({
    factor,
    bands,
    overall,
    noun,
}: {
    factor: RiskFactor;
    bands: NonNullable<RiskReference['evidence']>['features'][string];
    overall: number | null;
    noun: string;
}) {
    const own = bands.find((b) => b.band === factor.band);
    const typical = bands.find((b) => b.band === factor.typical_band);
    const max = Math.max(0.01, ...bands.map((b) => b.rate), overall ?? 0);
    const consistent = factor.evidence?.consistent;

    if (!own) {
        return null;
    }

    return (
        <li className="flex flex-col gap-3 p-3 sm:flex-row sm:items-start">
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <span className="text-sm font-medium">
                    {inputLabel(factor)}
                </span>
                <p className="text-xs text-muted-foreground">
                    Of the {own.people} {noun} at “{own.band}”,{' '}
                    <span className="font-medium text-foreground">
                        {own.left} left ({formatShare(own.rate)})
                    </span>
                    {typical && typical.band !== own.band
                        ? ` — against ${formatShare(typical.rate)} at the typical “${typical.band}”.`
                        : '.'}
                    {own.people < FEW &&
                        ' Few people gave this answer, so read it lightly.'}
                </p>
                {consistent !== true && (
                    <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                        <Info className="mt-0.5 size-3 shrink-0" />
                        {consistent === false
                            ? 'On its own this answer points the other way. The model counts it because of how it combines with this person’s other answers.'
                            : 'On its own this answer makes little difference. The model counts it because of how it combines with this person’s other answers.'}
                    </p>
                )}
            </div>

            <figure
                className="w-full shrink-0 sm:w-56"
                aria-label={`Share who left by answer to ${inputLabel(factor).toLowerCase()}`}
            >
                <div className="relative flex h-16 items-end gap-[2px] border-b border-border">
                    {overall !== null && (
                        <div
                            className="pointer-events-none absolute inset-x-0 border-t border-foreground/40"
                            style={{ bottom: `${(overall / max) * 100}%` }}
                        />
                    )}
                    {bands.map((band) => {
                        const mine = band.band === own.band;

                        return (
                            <Tooltip key={band.band}>
                                <TooltipTrigger asChild>
                                    <div
                                        tabIndex={0}
                                        aria-label={`${band.band}: ${band.left} of ${band.people} left`}
                                        className="flex h-full flex-1 items-end justify-center outline-none focus-visible:bg-muted/60"
                                    >
                                        <div
                                            className={cn(
                                                'w-full max-w-6 rounded-t-[4px]',
                                                mine
                                                    ? 'bg-[#0ABFBF]'
                                                    : 'bg-muted-foreground/30',
                                            )}
                                            style={{
                                                height: `max(2px, ${(band.rate / max) * 100}%)`,
                                            }}
                                        />
                                    </div>
                                </TooltipTrigger>
                                <TooltipContent side="top">
                                    <p className="font-semibold tabular-nums">
                                        {formatShare(band.rate)} left
                                    </p>
                                    <p className="opacity-80">
                                        {band.left} of {band.people} at “
                                        {band.band}”
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        );
                    })}
                </div>
                <div className="mt-1 flex gap-[2px]">
                    {bands.map((band) => (
                        <span
                            key={band.band}
                            className={cn(
                                'flex-1 text-center text-[9px] leading-tight break-words text-muted-foreground',
                                band.band === own.band &&
                                    'font-semibold text-foreground',
                            )}
                        >
                            {band.band}
                        </span>
                    ))}
                </div>
                <figcaption className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[10px] text-muted-foreground">
                    <span className="flex items-center gap-1.5">
                        <span className="size-2 rounded-[2px] bg-[#0ABFBF]" />
                        This person’s answer
                    </span>
                    {overall !== null && (
                        <span className="flex items-center gap-1.5">
                            <span className="h-px w-3 bg-foreground/50" />
                            {formatShare(overall)} left overall
                        </span>
                    )}
                </figcaption>
            </figure>
        </li>
    );
}
