import { cn } from '@/lib/utils';
import { GOAL_HEALTH, GOAL_STATUS, PILL } from '../constants';
import type { GoalHealth, GoalStatus, PerformanceGoal } from '../types';

/**
 * How far along a goal is: a bar coloured by how it is going (teal before
 * anyone has said), with where it stands in its own terms beside it — "31 of
 * 40 deals", not a bare percentage.
 */
export function GoalProgressBar({
    goal,
    className,
    showLabel = true,
}: {
    goal: Pick<
        PerformanceGoal,
        | 'progress'
        | 'health'
        | 'status'
        | 'measure'
        | 'current_label'
        | 'target_label'
    >;
    className?: string;
    showLabel?: boolean;
}) {
    const fill =
        goal.status === 'achieved'
            ? 'bg-emerald-500'
            : goal.status === 'dropped'
              ? 'bg-muted-foreground/40'
              : goal.health
                ? GOAL_HEALTH[goal.health].bar
                : 'bg-[#0ABFBF]';

    return (
        <div className={cn('flex min-w-0 items-center gap-2.5', className)}>
            <div
                className="h-1.5 min-w-16 flex-1 overflow-hidden rounded-full bg-muted"
                role="progressbar"
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={Math.round(goal.progress)}
                aria-label="Progress"
            >
                <div
                    className={cn('h-full rounded-full transition-all', fill)}
                    style={{ width: `${goal.progress}%` }}
                />
            </div>
            {showLabel && (
                <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                    <span className="font-semibold text-foreground">
                        {Math.round(goal.progress)}%
                    </span>
                    {goal.measure === 'number' &&
                        ` · ${goal.current_label}, target ${goal.target_label}`}
                </span>
            )}
        </div>
    );
}

/** How a goal is going, from its latest check-in. */
export function GoalHealthChip({
    health,
    className,
}: {
    health: GoalHealth | null;
    className?: string;
}) {
    if (health === null) {
        return (
            <span
                className={cn(
                    PILL,
                    'border-dashed border-border text-muted-foreground',
                    className,
                )}
            >
                No check-in yet
            </span>
        );
    }

    return (
        <span className={cn(PILL, GOAL_HEALTH[health].className, className)}>
            {GOAL_HEALTH[health].label}
        </span>
    );
}

export function GoalStatusChip({
    status,
    className,
}: {
    status: GoalStatus;
    className?: string;
}) {
    return (
        <span className={cn(PILL, GOAL_STATUS[status].className, className)}>
            {GOAL_STATUS[status].label}
        </span>
    );
}

/** A goal's state in one chip: its status once closed, else how it's going. */
export function GoalStateChip({ goal }: { goal: PerformanceGoal }) {
    return goal.status === 'active' ? (
        <GoalHealthChip health={goal.health} />
    ) : (
        <GoalStatusChip status={goal.status} />
    );
}
