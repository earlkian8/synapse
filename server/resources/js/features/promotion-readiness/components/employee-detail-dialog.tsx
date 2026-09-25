import { CircleAlert, Layers } from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import {
    BASIS_LABELS,
    BASIS_NOTES,
    formatChange,
    formatLift,
    formatProbability,
    formatScore,
    REFERENCE_PROMOTION_RATE,
    TIER_DESCRIPTIONS,
    tierBarTone,
    tierTone,
} from '../constants';
import type { ReadinessScore } from '../types';
import { FactorList } from './factor-list';
import { TierBadge } from './tier-badge';

/**
 * A drill-down on one employee's readiness: what the score means, how much record
 * it rests on, what moves it, and the appraisals it was built from.
 */
export function EmployeeDetailDialog({
    score,
    onOpenChange,
}: {
    score: ReadinessScore | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={score !== null} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                {score && score.employee && (
                    <>
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-3">
                                <PersonAvatar
                                    name={score.employee.full_name}
                                    initials={score.employee.initials}
                                    photo={score.employee.photo}
                                    className="size-10"
                                />
                                <span className="flex min-w-0 flex-col">
                                    <span className="truncate text-base">
                                        {score.employee.full_name}
                                    </span>
                                    <span className="truncate text-xs font-normal text-muted-foreground">
                                        {score.employee.position ??
                                            score.employee.employee_no}
                                        {score.employee.department
                                            ? ` · ${score.employee.department}`
                                            : ''}
                                    </span>
                                </span>
                            </DialogTitle>
                            <DialogDescription className="sr-only">
                                Promotion-readiness breakdown for{' '}
                                {score.employee.full_name}
                            </DialogDescription>
                        </DialogHeader>

                        {/* Score headline */}
                        <div className="flex flex-col gap-2 rounded-xl border border-sidebar-border/70 bg-card/60 p-4 dark:border-sidebar-border">
                            <div className="flex items-end justify-between">
                                <div className="flex items-baseline gap-1.5">
                                    <span
                                        className={cn(
                                            'text-4xl font-semibold tracking-tight tabular-nums',
                                            tierTone(score.tier),
                                        )}
                                    >
                                        {formatScore(score.score)}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        / 100 readiness
                                    </span>
                                </div>
                                <TierBadge tier={score.tier} />
                            </div>
                            <div className="h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    className={cn(
                                        'h-full rounded-full',
                                        tierBarTone(score.tier),
                                    )}
                                    style={{ width: `${score.score}%` }}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {TIER_DESCRIPTIONS[score.tier]}. Of people with
                                this record in the reference workforce,{' '}
                                <span className="font-medium text-foreground">
                                    {formatProbability(score.probability)}
                                </span>{' '}
                                were promoted within a year —{' '}
                                {formatLift(score.probability)} the average of{' '}
                                {formatProbability(REFERENCE_PROMOTION_RATE)}.
                                The score places that among the whole reference
                                workforce.
                            </p>
                        </div>

                        {/* What it rests on */}
                        {score.basis && (
                            <div className="flex items-start gap-3 rounded-xl border border-sidebar-border/60 bg-card/40 px-4 py-3 dark:border-sidebar-border">
                                <Layers
                                    className={cn(
                                        'mt-0.5 size-4 shrink-0',
                                        score.basis === 'two_appraisals'
                                            ? 'text-[#0ABFBF]'
                                            : 'text-amber-500',
                                    )}
                                />
                                <div className="flex flex-col gap-0.5">
                                    <span className="text-sm font-medium">
                                        Based on{' '}
                                        {BASIS_LABELS[
                                            score.basis
                                        ].toLowerCase()}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {BASIS_NOTES[score.basis]}
                                    </span>
                                </div>
                            </div>
                        )}

                        {/* What moves it */}
                        {score.factors.length > 0 && (
                            <div className="flex flex-col gap-2">
                                <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                    What moves this score
                                </h3>
                                <FactorList factors={score.factors} />
                                <p className="text-xs text-muted-foreground">
                                    Readiness points each input adds or takes
                                    away, compared with a typical record.
                                </p>
                            </div>
                        )}

                        {/* What it is based on */}
                        <div className="flex flex-col gap-2">
                            <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                What this is based on
                            </h3>
                            <AppraisalGrid score={score} />
                            {score.warnings.length > 0 && (
                                <ul className="flex flex-col gap-1 rounded-lg border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-300">
                                    {score.warnings.map((warning) => (
                                        <li
                                            key={warning}
                                            className="flex items-start gap-1.5"
                                        >
                                            <CircleAlert className="mt-0.5 size-3 shrink-0" />
                                            {warning}
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <p className="mt-1 text-xs text-muted-foreground">
                                A readiness score is a prompt for a
                                conversation, not a decision. It compares this
                                appraisal record with those of people who were
                                promoted in a general reference workforce — it
                                cannot see skills, role openings or anything
                                else a promotion case weighs. Department, pay
                                and every demographic attribute are left out.
                            </p>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function AppraisalGrid({ score }: { score: ReadinessScore }) {
    const [previous, latest] =
        score.history.length >= 2
            ? [score.history[score.history.length - 2], score.history.at(-1)]
            : [null, score.history.at(-1) ?? null];
    const change = score.features.rating_change;

    const cells: { label: string; value: string | null; hint?: string }[] = [
        {
            label: 'Latest appraisal',
            value:
                score.features.rating_latest === undefined
                    ? null
                    : `${score.features.rating_latest.toFixed(1)}%`,
            hint: latest?.label ?? undefined,
        },
        {
            label: 'Previous appraisal',
            value: previous ? `${previous.rating.toFixed(1)}%` : null,
            hint: previous?.label ?? undefined,
        },
        {
            label: 'Change',
            value: change === undefined ? null : formatChange(change),
        },
    ];

    return (
        <dl className="grid grid-cols-3 gap-2">
            {cells.map((cell) => (
                <div
                    key={cell.label}
                    className="flex flex-col gap-0.5 rounded-lg border border-sidebar-border/60 bg-card/40 px-3 py-2 dark:border-sidebar-border"
                >
                    <dt className="text-[11px] text-muted-foreground">
                        {cell.label}
                    </dt>
                    <dd
                        className={cn(
                            'text-sm font-medium tabular-nums',
                            cell.value === null &&
                                'font-normal text-muted-foreground italic',
                        )}
                    >
                        {cell.value ?? 'Not on record'}
                    </dd>
                    {cell.hint && (
                        <span className="truncate text-[10px] text-muted-foreground">
                            {cell.hint}
                        </span>
                    )}
                </div>
            ))}
        </dl>
    );
}
