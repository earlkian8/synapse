import { Link } from '@inertiajs/react';
import { CalendarClock, CheckCircle2, TriangleAlert } from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import { formatShortDate } from '../constants';
import { offboardingRoutes } from '../routes';
import type { OffboardingCase } from '../types';
import { CaseStatusBadge } from './case-status-badge';
import { ProgressBar } from './progress-bar';
import { TypeBadge } from './type-badge';

export function OffboardingCaseCard({ case: c }: { case: OffboardingCase }) {
    const employee = c.employee;
    const { clearance } = c;
    const isDone = c.status === 'completed';
    const isCancelled = c.status === 'cancelled';

    return (
        <Link
            href={offboardingRoutes.show(c.hashid)}
            className="group flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm transition-all hover:border-[#0ABFBF]/40 hover:shadow-md dark:border-sidebar-border"
        >
            <div className="flex items-start gap-3">
                <PersonAvatar
                    name={employee?.full_name ?? 'Unknown employee'}
                    initials={employee?.initials ?? '?'}
                    photo={employee?.photo}
                    className="size-10"
                    fallbackClassName="text-xs"
                />
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

            <div>
                <TypeBadge type={c.type} />
            </div>

            <div className="space-y-1.5">
                <div className="flex items-center justify-between text-xs">
                    <span className="font-medium text-foreground tabular-nums">
                        {clearance.cleared}/{clearance.total} cleared
                    </span>
                    <span className="text-muted-foreground tabular-nums">
                        {clearance.percent}%
                    </span>
                </div>
                <ProgressBar percent={clearance.percent} muted={isCancelled} />
            </div>

            <div className="flex items-center justify-between text-[11px] text-muted-foreground">
                <span className="inline-flex items-center gap-1">
                    {isDone ? (
                        <>
                            <CheckCircle2 className="size-3.5 text-emerald-500" />
                            Completed
                        </>
                    ) : c.last_working_day ? (
                        <>
                            <CalendarClock className="size-3.5" />
                            Last day {formatShortDate(c.last_working_day)}
                        </>
                    ) : (
                        <>
                            <CalendarClock className="size-3.5" />
                            No last day set
                        </>
                    )}
                </span>
                {clearance.flagged > 0 && !isCancelled && (
                    <span className="inline-flex items-center gap-1 font-medium text-rose-600 dark:text-rose-400">
                        <TriangleAlert className="size-3.5" />
                        {clearance.flagged} flagged
                    </span>
                )}
            </div>
        </Link>
    );
}
