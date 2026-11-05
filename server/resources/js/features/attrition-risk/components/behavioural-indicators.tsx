import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatPoints, formatShare } from '@/features/prediction-insights/lib';
import { cn } from '@/lib/utils';
import { componentsPhrase, formatInput } from '../explain';
import type { RiskIndicatorDefinition, RiskIndicatorReading } from '../types';

/** Fewer people than this at a level and its rate is flagged as thin. */
const FEW = 10;

/** Where an indicator's level comes from, as a sentence. */
function readFrom(reading: RiskIndicatorReading): string | null {
    const inputs = reading.inputs;

    if (reading.key === 'career_stagnation') {
        const wait = inputs.years_since_promotion;

        if (wait === undefined) {
            return null;
        }

        return Number(inputs.ever_promoted ?? 0) > 0
            ? `Read from the promotion record: last promoted ${formatInput('years_since_promotion', wait)} ago.`
            : `Read from the promotion record: ${formatInput('years_since_promotion', wait)} here, never promoted.`;
    }

    if (reading.key === 'effort_reward') {
        const effort = componentsPhrase({
            overtime_hours_90d: inputs.overtime_hours_90d ?? 0,
        });
        const reward = [
            inputs.monthly_salary !== undefined
                ? `pay of ${formatInput('monthly_salary', inputs.monthly_salary)}`
                : null,
            inputs.years_since_promotion !== undefined
                ? `${formatInput('years_since_promotion', inputs.years_since_promotion)} since a promotion`
                : null,
        ].filter(Boolean);

        return `Read from ${effort} in 90 days${reward.length > 0 ? `, against ${reward.join(' and ')}` : ''}.`;
    }

    const phrase = componentsPhrase(inputs);

    return phrase ? `Read from the last 90 days: ${phrase}.` : null;
}

/**
 * Behavioural indicators of emotional states (ADR 0101). The survey asked nothing about
 * feelings and the ERP records none, so each indicator reads behaviour that research
 * links to a feeling — withdrawal from absences and lateness, workload strain from
 * overtime. Each row says what the person's record reads as, whether the score reads
 * it (and the points it gives it), and how often people at that level left in the data
 * the model learned from. An indicator the score does not read is shown with the
 * reason, so the reader sees what was tested, not only what was kept.
 */
export function BehaviouralIndicators({
    readings,
    definitions,
    noun,
}: {
    readings: RiskIndicatorReading[];
    definitions: RiskIndicatorDefinition[];
    /** "surveyed" or "in your records". */
    noun: string;
}) {
    const byKey = Object.fromEntries(definitions.map((d) => [d.key, d]));
    const shown = readings.filter((r) => byKey[r.key]);

    if (shown.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-col divide-y divide-border rounded-xl border border-sidebar-border/60 dark:border-sidebar-border">
            {shown.map((reading) => (
                <IndicatorRow
                    key={reading.key}
                    reading={reading}
                    definition={byKey[reading.key]}
                    noun={noun}
                />
            ))}
        </ul>
    );
}

function IndicatorRow({
    reading,
    definition,
    noun,
}: {
    reading: RiskIndicatorReading;
    definition: RiskIndicatorDefinition;
    noun: string;
}) {
    const from = readFrom(reading);
    const points = reading.points;
    const moved = points !== null && Math.abs(points) >= 0.5 ? points : null;
    const evidence = reading.evidence;

    return (
        <li className="flex flex-col gap-3 p-3 sm:flex-row sm:items-start">
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="text-sm">
                    <span className="font-medium">{definition.label}</span>
                    <span className="text-muted-foreground">
                        {' '}
                        · {definition.indicates.toLowerCase()}
                    </span>
                </p>
                {from && (
                    <p className="text-xs text-muted-foreground">{from}</p>
                )}

                {reading.read === 'none' ? (
                    <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                        <Minus className="mt-0.5 size-3 shrink-0" />
                        <span>
                            <span className="font-medium text-foreground">
                                Not in the score.
                            </span>{' '}
                            {definition.not_read}
                        </span>
                    </p>
                ) : (
                    <p className="flex items-start gap-1.5 text-xs text-muted-foreground">
                        {moved !== null && moved > 0 ? (
                            <ArrowUpRight className="mt-0.5 size-3 shrink-0 text-rose-500" />
                        ) : moved !== null ? (
                            <ArrowDownRight className="mt-0.5 size-3 shrink-0 text-teal-600" />
                        ) : (
                            <Minus className="mt-0.5 size-3 shrink-0" />
                        )}
                        <span>
                            <span className="font-medium text-foreground">
                                {moved !== null
                                    ? `In the score: ${formatPoints(moved, 1)} points.`
                                    : 'In the score, no effect here.'}
                            </span>{' '}
                            {reading.read === 'inputs'
                                ? 'Read through the inputs it is built from.'
                                : 'Read as one input.'}
                        </span>
                    </p>
                )}

                {evidence && (
                    <p className="text-xs text-muted-foreground">
                        Of the {evidence.people} {noun} at “{reading.level}”,{' '}
                        {formatShare(evidence.rate)} left
                        {evidence.overall_rate !== null
                            ? ` (${formatShare(evidence.overall_rate)} overall).`
                            : '.'}
                        {evidence.people < FEW &&
                            ' Few people were at this level, so read it lightly.'}
                    </p>
                )}
            </div>

            <LevelMeter
                levels={definition.levels}
                rank={reading.rank}
                label={definition.label}
            />
        </li>
    );
}

/** The level as a row of segments, filled up to this person's. */
function LevelMeter({
    levels,
    rank,
    label,
}: {
    levels: string[];
    rank: number;
    label: string;
}) {
    return (
        <figure
            className="w-full shrink-0 sm:w-44"
            aria-label={`${label}: ${levels[rank]}, level ${rank + 1} of ${levels.length}`}
        >
            <p className="mb-1 text-right text-xs font-semibold">
                {levels[rank]}
            </p>
            <div className="flex gap-[3px]">
                {levels.map((level, i) => (
                    <Tooltip key={level}>
                        <TooltipTrigger asChild>
                            <div
                                tabIndex={0}
                                aria-label={level}
                                className={cn(
                                    'h-2 flex-1 rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    i <= rank
                                        ? 'bg-[#0ABFBF]'
                                        : 'bg-muted-foreground/20',
                                )}
                            />
                        </TooltipTrigger>
                        <TooltipContent side="top">{level}</TooltipContent>
                    </Tooltip>
                ))}
            </div>
            <figcaption className="mt-1 flex justify-between text-[10px] text-muted-foreground">
                <span>{levels[0]}</span>
                <span>{levels[levels.length - 1]}</span>
            </figcaption>
        </figure>
    );
}
