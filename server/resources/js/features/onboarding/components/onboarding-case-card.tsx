import { Link } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, CheckCircle2 } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { cn } from '@/lib/utils';
import { onboardingRoutes } from '../routes';
import type { OnboardingCase } from '../types';
import { CaseStatusBadge } from './case-status-badge';
import { ProgressBar } from './progress-bar';

export function OnboardingCaseCard({ case: c }: { case: OnboardingCase }) {
    const employee = c.employee;
    const { progress } = c;
    const isDone = c.status === 'completed';
    const isCancelled = c.status === 'cancelled';

    return (
        <Link
            href={onboardingRoutes.show(c.hashid)}
            className="group flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm transition-all hover:border-[#0ABFBF]/40 hover:shadow-md dark:border-sidebar-border"
        >
            <div className="flex items-start gap-3">
                <Avatar className="size-10 rounded-lg ring-1 ring-border">
                    {employee?.photo && (
                        <AvatarImage
                            src={employee.photo}
                            alt={employee.full_name}
                        />
                    )}
                    <AvatarFallback className="rounded-lg bg-[#0F2044] text-xs font-semibold text-white">
                        {employee?.initials ?? '?'}
                    </AvatarFallback>
                </Avatar>
                <div className="min-w-0 flex-1">
                    <div className="flex items-start justify-between gap-2">
                        <span className="block truncate text-sm font-semibold group-hover:text-[#0ABFBF]">
                            {employee?.full_name ?? 'Unknown employee'}
                        </span>
                        <CaseStatusBadge status={c.status} />
                    </div>
                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                        {employee?.position?.title ?? 'No position'}
                        {employee?.department
                            ? ` · ${employee.department.name}`
                            : ''}
                    </p>
                </div>
            </div>

            <div className="space-y-1.5">
                <div className="flex items-center justify-between text-xs">
                    <span className="font-medium tabular-nums text-foreground">
                        {progress.resolved}/{progress.total} tasks
                    </span>
                    <span className="tabular-nums text-muted-foreground">
                        {progress.percent}%
                    </span>
                </div>
                <ProgressBar
                    percent={progress.percent}
                    muted={isCancelled}
                />
            </div>

            <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                <span className="inline-flex items-center gap-1">
                    {isDone ? (
                        <>
                            <CheckCircle2 className="size-3.5 text-emerald-500" />
                            Completed
                        </>
                    ) : c.target_end_date ? (
                        <>
                            <CalendarClock className="size-3.5" />
                            Target {formatDate(c.target_end_date)}
                        </>
                    ) : (
                        <>
                            <CalendarClock className="size-3.5" />
                            No target date
                        </>
                    )}
                </span>
                {progress.overdue > 0 && !isDone && !isCancelled && (
                    <span
                        className={cn(
                            'inline-flex items-center gap-1 font-medium text-rose-600 dark:text-rose-400',
                        )}
                    >
                        <AlertTriangle className="size-3.5" />
                        {progress.overdue} overdue
                    </span>
                )}
            </div>
        </Link>
    );
}

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}
