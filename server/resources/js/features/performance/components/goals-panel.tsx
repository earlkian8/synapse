import { Link } from '@inertiajs/react';
import { Flag } from 'lucide-react';
import { TableCard } from '@/components/data-table';
import { sinceLabel } from '../constants';
import { performanceRoutes } from '../routes';
import type { PerformanceGoal } from '../types';
import { GoalProgressBar, GoalStateChip } from './goal-progress';

/**
 * The person's goals for the appraisal's cycle (ADR 0073), beside the scorecard:
 * how far each got, how it was going at the last check-in, and what they add up
 * to. Decision support for the evaluator — it never sets a rating.
 */
export function GoalsPanel({
    goals,
    attainment,
    subject,
    periodId,
}: {
    goals: PerformanceGoal[];
    attainment: number | null;
    subject: string;
    periodId: number | null;
}) {
    return (
        <TableCard
            title="Goals this cycle"
            count={goals.length}
            description={
                goals.length > 0
                    ? `What ${subject} committed to, and how far each got. Read it beside the ratings; it doesn't set them.`
                    : undefined
            }
            actions={
                attainment !== null ? (
                    <span className="text-xs text-muted-foreground tabular-nums">
                        <span className="font-semibold text-foreground">
                            {Math.round(attainment)}%
                        </span>{' '}
                        goal attainment
                    </span>
                ) : undefined
            }
        >
            {goals.length === 0 ? (
                <div className="flex items-center gap-3 px-4 py-4 text-sm text-muted-foreground">
                    <Flag className="size-4 shrink-0" />
                    <span>
                        No goals were set for {subject} in this cycle.{' '}
                        <Link
                            href={performanceRoutes.goals(periodId)}
                            className="text-[#08767c] underline-offset-2 hover:underline dark:text-[#0ABFBF]"
                        >
                            Open Goals
                        </Link>
                    </span>
                </div>
            ) : (
                <ul className="divide-y divide-border">
                    {goals.map((goal) => (
                        <li
                            key={goal.id}
                            className="flex flex-col gap-2 px-4 py-2.5 md:flex-row md:items-center md:gap-4"
                        >
                            <div className="min-w-0 md:w-2/5">
                                <Link
                                    href={`${performanceRoutes.goals(periodId)}${periodId ? '&' : '?'}goal=${goal.hashid}`}
                                    className="block truncate text-sm font-medium hover:underline"
                                >
                                    {goal.title}
                                </Link>
                                <p className="text-xs text-muted-foreground">
                                    {goal.last_check_in_at
                                        ? `Checked in ${sinceLabel(goal.last_check_in_at)}`
                                        : 'No check-in yet'}
                                    {goal.weight !== 1 &&
                                        ` · weight ${goal.weight}`}
                                </p>
                            </div>
                            <GoalProgressBar goal={goal} className="flex-1" />
                            <GoalStateChip goal={goal} />
                        </li>
                    ))}
                </ul>
            )}
        </TableCard>
    );
}
