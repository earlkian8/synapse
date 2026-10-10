import { Head, router, usePage } from '@inertiajs/react';
import { CalendarRange, Flag, Plus } from 'lucide-react';
import { useState } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { GoalDialog } from '@/features/performance/components/goal-dialog';
import { GoalFormModal } from '@/features/performance/components/goal-form-modal';
import {
    GoalProgressBar,
    GoalStateChip,
} from '@/features/performance/components/goal-progress';
import { PerformanceNav } from '@/features/performance/components/performance-nav';
import { PeriodStatusBadge } from '@/features/performance/components/status-badge';
import { formatDate, sinceLabel } from '@/features/performance/constants';
import { performanceRoutes } from '@/features/performance/routes';
import type { MyGoalsPageProps } from '@/features/performance/types';
import { cn } from '@/lib/utils';

/**
 * My goals (ADR 0073): what the signed-in employee committed to in a cycle,
 * how far each has got, and a check-in on any of them — the value now, how it
 * is going, and a note. In an open cycle they can add a goal of their own.
 */
export default function MyGoals() {
    const {
        goals,
        attainment,
        periods,
        currentPeriodId,
        templates,
        focus,
        has_employee,
        nav,
    } = usePage<MyGoalsPageProps>().props;

    const [openHashid, setOpenHashid] = useState<string | null>(focus);
    const [adding, setAdding] = useState(false);

    const period = periods.find((p) => p.id === currentPeriodId) ?? null;
    const opened = goals.find((g) => g.hashid === openHashid) ?? null;
    const active = goals.filter((g) => g.status === 'active');

    return (
        <>
            <Head title="My goals" />

            <PageBody>
                <PageHeader
                    title="My goals"
                    description={
                        periods.length > 1 ? (
                            <div className="mt-1 flex flex-wrap items-center gap-2">
                                <span className="flex items-center gap-1.5">
                                    <CalendarRange className="size-4" />
                                    Review cycle
                                </span>
                                <Select
                                    value={
                                        currentPeriodId === null
                                            ? ''
                                            : String(currentPeriodId)
                                    }
                                    onValueChange={(value) =>
                                        router.get(
                                            performanceRoutes.myGoals(
                                                Number(value),
                                            ),
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <SelectTrigger
                                        size="sm"
                                        className="w-56 text-foreground"
                                        aria-label="Review cycle"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {periods.map((p) => (
                                            <SelectItem
                                                key={p.id}
                                                value={String(p.id)}
                                            >
                                                {p.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {period && (
                                    <PeriodStatusBadge status={period.status} />
                                )}
                            </div>
                        ) : period ? (
                            `Your goals for ${period.name}. Check in as they move.`
                        ) : (
                            'Your goals, cycle by cycle.'
                        )
                    }
                    actions={<PerformanceNav current="my-goals" counts={nav} />}
                />

                {!has_employee ? (
                    <Empty
                        title="Your account isn’t linked to an employee"
                        body="Goals belong to employees. Ask HR to link your account to your employee record."
                    />
                ) : (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-3">
                            <p className="text-sm text-muted-foreground">
                                {goals.length === 0
                                    ? period
                                        ? `Nothing set for ${period.name} yet.`
                                        : 'No review cycle is open.'
                                    : `${active.length} active of ${goals.length}`}
                                {attainment !== null && (
                                    <>
                                        {' · '}
                                        <span className="font-semibold text-foreground tabular-nums">
                                            {Math.round(attainment)}%
                                        </span>{' '}
                                        overall
                                    </>
                                )}
                            </p>
                            {period?.status === 'open' && (
                                <Button
                                    size="sm"
                                    onClick={() => setAdding(true)}
                                >
                                    <Plus className="size-4" />
                                    Add a goal
                                </Button>
                            )}
                        </div>

                        {goals.length === 0 ? (
                            <Empty
                                title="No goals yet"
                                body={
                                    period?.status === 'open'
                                        ? 'HR or your manager may set goals for you, or you can add your own. Check in on them as the cycle goes.'
                                        : 'Goals set for you land here, and you’re told when one is.'
                                }
                            />
                        ) : (
                            <ul className="grid gap-3 lg:grid-cols-2">
                                {goals.map((goal) => (
                                    <li key={goal.id}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setOpenHashid(goal.hashid)
                                            }
                                            className={cn(
                                                'flex h-full w-full flex-col gap-3 rounded-xl border bg-card p-4 text-left transition-colors hover:border-[#0ABFBF]/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                                goal.is_stale
                                                    ? 'border-amber-500/40'
                                                    : 'border-sidebar-border/70 dark:border-sidebar-border',
                                            )}
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <p className="font-medium">
                                                    {goal.title}
                                                </p>
                                                <GoalStateChip goal={goal} />
                                            </div>
                                            <GoalProgressBar goal={goal} />
                                            <p className="text-xs text-muted-foreground">
                                                {goal.due_on
                                                    ? `Due ${formatDate(goal.due_on)} · `
                                                    : ''}
                                                {goal.last_check_in_at
                                                    ? `checked in ${sinceLabel(goal.last_check_in_at)}`
                                                    : 'not checked in on yet'}
                                                {goal.is_stale &&
                                                    ' — time for an update'}
                                            </p>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </>
                )}
            </PageBody>

            <GoalDialog
                goal={opened}
                onOpenChange={(open) => !open && setOpenHashid(null)}
                as="owner"
            />

            {period && (
                <GoalFormModal
                    open={adding}
                    onOpenChange={setAdding}
                    mode={{ kind: 'own' }}
                    periods={[period]}
                    defaultPeriodId={period.id}
                    templates={templates}
                />
            )}
        </>
    );
}

function Empty({ title, body }: { title: string; body: string }) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border px-6 py-14 text-center">
            <Flag className="size-8 text-muted-foreground" aria-hidden />
            <p className="font-medium">{title}</p>
            <p className="max-w-sm text-sm text-muted-foreground">{body}</p>
        </div>
    );
}

MyGoals.layout = {
    breadcrumbs: [
        { title: 'Performance Management', href: '/performance' },
        { title: 'My goals', href: '/performance/me/goals' },
    ],
};
