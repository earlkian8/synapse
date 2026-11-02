import { ArrowDownRight, ArrowUpRight, Minus } from 'lucide-react';
import { formatPoints, POLARITY } from '@/features/prediction-insights/lib';
import { cn } from '@/lib/utils';
import { scenarioLabel } from '../brief';
import type { RiskScore } from '../types';

/**
 * What the model would score with one change HR can make (ADR 0099): each scenario
 * as a row with today's score and the scenario's on one 0–100 track, joined by the
 * move between them — teal when it lowers risk, rose when it raises it, with a
 * signed number and an arrow either way. Sorted by how much it lowers risk, so a
 * change that would not help is shown, honestly, last.
 */
export function ScenarioList({
    score,
    guides,
}: {
    score: RiskScore;
    guides: { value: number; label: string }[];
}) {
    const scenarios = [...(score.explanation?.scenarios ?? [])].sort(
        (a, b) => a.score - b.score,
    );

    if (scenarios.length === 0) {
        return null;
    }

    return (
        <ul className="flex flex-col divide-y divide-border rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
            {scenarios.map((scenario) => {
                const { title, detail } = scenarioLabel(scenario, score);
                const move = scenario.score - score.score;
                const flat = Math.abs(move) < 0.5;
                const lowers = move < 0;
                const lo = Math.min(score.score, scenario.score);
                const hi = Math.max(score.score, scenario.score);
                const Icon = flat
                    ? Minus
                    : lowers
                      ? ArrowDownRight
                      : ArrowUpRight;

                return (
                    <li
                        key={scenario.key}
                        className={cn(
                            'grid grid-cols-1 gap-2 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,13rem)] sm:items-center sm:gap-5',
                            scenario.key === 'together' && 'bg-muted/25',
                        )}
                    >
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <span className="text-sm font-medium">{title}</span>
                            <span className="text-xs text-muted-foreground">
                                {detail}
                            </span>
                        </div>
                        <div className="flex flex-col gap-1">
                            <div className="flex items-baseline justify-between text-xs tabular-nums">
                                <span className="text-muted-foreground">
                                    {Math.round(score.score)} →{' '}
                                    <span className="font-semibold text-foreground">
                                        {Math.round(scenario.score)}
                                    </span>
                                </span>
                                <span className="flex items-center gap-0.5 font-medium">
                                    <Icon
                                        className={cn(
                                            'size-3.5',
                                            flat
                                                ? 'text-muted-foreground'
                                                : lowers
                                                  ? 'text-teal-600'
                                                  : 'text-rose-600 dark:text-rose-500',
                                        )}
                                    />
                                    {flat ? 'No change' : formatPoints(move)}
                                </span>
                            </div>
                            <div
                                className="relative h-3 rounded-full bg-muted/60"
                                role="img"
                                aria-label={`${title}: risk ${Math.round(score.score)} to ${Math.round(scenario.score)}`}
                            >
                                {guides.map((g) => (
                                    <span
                                        key={g.label}
                                        aria-hidden
                                        className="absolute inset-y-0 border-l border-foreground/20"
                                        style={{ left: `${g.value}%` }}
                                    />
                                ))}
                                {!flat && (
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'absolute inset-y-[3px] rounded-full opacity-80',
                                            lowers
                                                ? POLARITY.favourable.fill
                                                : POLARITY.unfavourable.fill,
                                        )}
                                        style={{
                                            left: `${lo}%`,
                                            width: `${hi - lo}%`,
                                        }}
                                    />
                                )}
                                <span
                                    aria-hidden
                                    className="absolute top-1/2 size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-foreground/60 bg-card"
                                    style={{ left: `${score.score}%` }}
                                />
                                <span
                                    aria-hidden
                                    className="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-foreground ring-2 ring-card"
                                    style={{ left: `${scenario.score}%` }}
                                />
                            </div>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
