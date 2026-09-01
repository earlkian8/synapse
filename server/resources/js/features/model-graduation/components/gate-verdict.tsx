import { GraduationCap, Lock } from 'lucide-react';
import { cn } from '@/lib/utils';
import { completion, formatProgress, projectionDistance } from '../constants';
import type { GraduationCheck, Requirement } from '../types';

/**
 * The verdict panel — deliberately the loudest thing on the page.
 *
 * Most readiness dashboards lead with progress. This one leads with the refusal,
 * because refusing to train on insufficient data is the designed behaviour, not
 * a failure state: a model fitted to fourteen examples would still produce
 * confident-looking scores, and those scores would be noise.
 */
export function GateVerdict({ check }: { check: GraduationCheck }) {
    const binding =
        check.requirements.find((r) => r.key === check.binding_key) ?? null;

    if (check.stage === 'graduated') {
        return <GraduatedVerdict check={check} />;
    }

    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
            <div className="flex flex-wrap items-start justify-between gap-3 border-b border-sidebar-border/70 bg-muted/40 px-4 py-4 sm:px-5 dark:border-sidebar-border">
                <div className="flex items-start gap-3">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-foreground/5 text-muted-foreground">
                        <Lock className="size-4" />
                    </span>
                    <div className="min-w-0">
                        <h2 className="text-base font-semibold tracking-tight">
                            Retraining locked
                        </h2>
                        <p className="mt-1 max-w-xl text-sm text-muted-foreground">
                            This organisation does not yet hold enough of its
                            own history to train a model on. Scoring continues
                            on the provisional model, and every prediction stays
                            labelled as such.
                        </p>
                    </div>
                </div>

                <div className="flex shrink-0 flex-col items-end">
                    <span className="text-2xl font-semibold tracking-tight tabular-nums">
                        {check.met_count}
                        <span className="text-muted-foreground">
                            /{check.total_count}
                        </span>
                    </span>
                    <span className="text-xs text-muted-foreground">
                        requirements met
                    </span>
                </div>
            </div>

            {binding && <BindingConstraint check={check} binding={binding} />}
        </div>
    );
}

/** The single requirement furthest from being satisfied, with its arithmetic. */
function BindingConstraint({
    check,
    binding,
}: {
    check: GraduationCheck;
    binding: Requirement;
}) {
    const percent = completion(binding.current, binding.required);
    const remaining = Math.max(0, binding.required - binding.current);

    return (
        <div className="px-4 py-4 sm:px-5">
            <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                Holding this back
            </span>

            <div className="mt-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <p className="text-sm font-medium">{binding.label}</p>
                <p className="text-sm font-semibold tabular-nums">
                    {formatProgress(binding.current, binding.required)}
                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                        {percent}%
                    </span>
                </p>
            </div>

            <div className="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full bg-[#0ABFBF]"
                    style={{ width: `${Math.max(percent, 1.5)}%` }}
                />
            </div>

            <p className="mt-3 text-sm text-muted-foreground">
                {binding.key === 'promotion_outcomes' ? (
                    <>
                        At {check.observed_rate} promotions a year on current
                        records, the remaining{' '}
                        <span className="font-medium text-foreground tabular-nums">
                            {remaining}
                        </span>{' '}
                        is{' '}
                        <span className="font-medium text-foreground">
                            {projectionDistance(check.projected_year)}
                        </span>
                        {check.projected_year !== null && (
                            <>
                                {' '}
                                — around{' '}
                                <span className="font-medium text-foreground tabular-nums">
                                    {check.projected_year}
                                </span>
                            </>
                        )}
                        .
                    </>
                ) : (
                    <>
                        {remaining.toLocaleString()} more {binding.unit} needed
                        before this requirement is satisfied.
                    </>
                )}
            </p>
        </div>
    );
}

function GraduatedVerdict({ check }: { check: GraduationCheck }) {
    return (
        <div
            className={cn(
                'flex flex-wrap items-start justify-between gap-3 rounded-xl border p-4 shadow-sm sm:p-5',
                'border-emerald-500/30 bg-emerald-500/5',
            )}
        >
            <div className="flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600 dark:text-emerald-400">
                    <GraduationCap className="size-4" />
                </span>
                <div className="min-w-0">
                    <h2 className="text-base font-semibold tracking-tight">
                        Ready to retrain
                    </h2>
                    <p className="mt-1 max-w-xl text-sm text-muted-foreground">
                        Every requirement is satisfied. A model trained on this
                        organisation’s own records would replace the provisional
                        one, and predictions would stop carrying the provisional
                        label.
                    </p>
                </div>
            </div>

            <div className="flex shrink-0 flex-col items-end">
                <span className="text-2xl font-semibold tracking-tight text-emerald-600 tabular-nums dark:text-emerald-400">
                    {check.met_count}
                    <span className="opacity-60">/{check.total_count}</span>
                </span>
                <span className="text-xs text-muted-foreground">
                    requirements met
                </span>
            </div>
        </div>
    );
}
