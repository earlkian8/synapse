import { PersonAvatar } from '@/components/person-avatar';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { formatScore, scoreBarTone, scoreTone } from '../constants';
import type { ReadinessScore } from '../types';
import { FactorList } from './factor-list';
import { TierBadge } from './tier-badge';

/** A drill-down on one employee's readiness: score, tier, and the factors. */
export function EmployeeDetailDialog({
    score,
    onOpenChange,
}: {
    score: ReadinessScore | null;
    onOpenChange: (open: boolean) => void;
}) {
    return (
        <Dialog open={score !== null} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
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
                                            scoreTone(score.score),
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
                                        scoreBarTone(score.score),
                                    )}
                                    style={{ width: `${score.score}%` }}
                                />
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Model probability of promotion:{' '}
                                {(score.probability * 100).toFixed(1)}%
                            </p>
                        </div>

                        {/* Factors */}
                        <div className="flex flex-col gap-2">
                            <h3 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                What drives this score
                            </h3>
                            <FactorList factors={score.factors} />
                            <p className="mt-1 text-xs text-muted-foreground">
                                Green factors push toward promotion readiness;
                                red pull away. Demographic attributes are
                                excluded.
                            </p>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
