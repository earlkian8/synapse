import { Link } from '@inertiajs/react';
import { AlertTriangle, UserRoundCheck } from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    rowOpens,
    SortableHead,
    TableCard,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { onboardingRoutes } from '../routes';
import type { CaseFilters, CaseSort, OnboardingCase } from '../types';
import { CaseRowActions } from './case-row-actions';
import type { CaseRowHandlers } from './case-row-actions';
import { CaseStatusBadge } from './case-status-badge';
import { ProgressBar } from './progress-bar';

type Props = CaseRowHandlers & {
    cases: OnboardingCase[];
    filters: CaseFilters;
    canManage: boolean;
    filtered: boolean;
    onSort: (column: CaseSort) => void;
};

/**
 * The people one program is onboarding: where each checklist stands, what has
 * slipped, and when it is due. A row opens that person's checklist.
 */
export function CasesTable({
    cases,
    filters,
    canManage,
    filtered,
    onSort,
    ...handlers
}: Props) {
    const sortable = (column: CaseSort) => ({
        active: filters.sort === column,
        direction: filters.direction,
        onSort: () => onSort(column),
    });

    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <SortableHead {...sortable('employee')}>
                            Employee
                        </SortableHead>
                        <TableHead>Department</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="w-48">Checklist</TableHead>
                        <TableHead className="text-right">Overdue</TableHead>
                        <SortableHead {...sortable('start_date')}>
                            Started
                        </SortableHead>
                        <SortableHead {...sortable('target_end_date')}>
                            Target
                        </SortableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {cases.length === 0 && (
                        <EmptyTableRow
                            colSpan={8}
                            icon={UserRoundCheck}
                            title={
                                filtered
                                    ? 'Nobody matches'
                                    : 'Nobody is onboarding here yet'
                            }
                            description={
                                filtered
                                    ? 'Try adjusting the search or filters.'
                                    : 'New hires on this program land here automatically — or start onboarding for someone.'
                            }
                        />
                    )}

                    {cases.map((item) => (
                        <CaseRow
                            key={item.id}
                            item={item}
                            canManage={canManage}
                            {...handlers}
                        />
                    ))}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}

function CaseRow({
    item,
    canManage,
    ...handlers
}: CaseRowHandlers & { item: OnboardingCase; canManage: boolean }) {
    const href = onboardingRoutes.show(item.hashid);
    const employee = item.employee;
    const { progress } = item;
    const inFlight = item.is_active;

    return (
        <TableRow {...rowOpens(href)}>
            <TableCell>
                <div className="flex min-w-0 items-center gap-2.5">
                    <PersonAvatar
                        name={employee?.full_name ?? 'Unknown employee'}
                        initials={employee?.initials ?? '?'}
                        photo={employee?.photo}
                        className="size-8"
                        fallbackClassName="text-[11px]"
                    />
                    <div className="min-w-0">
                        <Link
                            href={href}
                            className="block truncate text-sm font-medium hover:text-[#0ABFBF]"
                        >
                            {employee?.full_name ?? 'Unknown employee'}
                        </Link>
                        <span className="block truncate font-mono text-[11px] text-muted-foreground">
                            {employee?.employee_no ?? '—'}
                        </span>
                    </div>
                </div>
            </TableCell>
            <TableCell>
                <span className="block truncate text-sm">
                    {employee?.department?.name ?? (
                        <span className="text-muted-foreground">—</span>
                    )}
                </span>
                {employee?.position && (
                    <span className="block truncate text-xs text-muted-foreground">
                        {employee.position.title}
                    </span>
                )}
            </TableCell>
            <TableCell>
                <CaseStatusBadge status={item.status} />
            </TableCell>
            <TableCell>
                <div className="flex items-center gap-2">
                    <ProgressBar
                        percent={progress.percent}
                        muted={item.status === 'cancelled'}
                        className="w-20"
                    />
                    <span className="text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                        {progress.resolved}/{progress.total}
                    </span>
                </div>
            </TableCell>
            <TableCell className="text-right text-sm tabular-nums">
                {inFlight && progress.overdue > 0 ? (
                    <span className="inline-flex items-center gap-1 font-medium text-rose-600 dark:text-rose-400">
                        <AlertTriangle className="size-3.5" />
                        {progress.overdue}
                    </span>
                ) : (
                    <span className="text-muted-foreground">0</span>
                )}
            </TableCell>
            <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                {formatDate(item.start_date)}
            </TableCell>
            <TableCell className="text-sm whitespace-nowrap">
                <TargetDate item={item} />
            </TableCell>
            <TableCell className="text-right">
                <CaseRowActions
                    item={item}
                    canManage={canManage}
                    {...handlers}
                />
            </TableCell>
        </TableRow>
    );
}

/** The target, flagged when an in-flight case has run past it. */
function TargetDate({ item }: { item: OnboardingCase }) {
    if (item.status === 'completed') {
        return (
            <span className="text-muted-foreground">
                Done {formatDate(item.completed_at)}
            </span>
        );
    }

    if (!item.target_end_date) {
        return <span className="text-muted-foreground">—</span>;
    }

    const late = item.is_active && item.target_end_date < localToday();

    return (
        <span
            className={cn(
                late
                    ? 'font-medium text-rose-600 dark:text-rose-400'
                    : 'text-muted-foreground',
            )}
            title={late ? 'Past its target date' : undefined}
        >
            {formatDate(item.target_end_date)}
        </span>
    );
}

/** Today as YYYY-MM-DD on the viewer's own calendar (not UTC's). */
function localToday(): string {
    const now = new Date();

    return [
        now.getFullYear(),
        String(now.getMonth() + 1).padStart(2, '0'),
        String(now.getDate()).padStart(2, '0'),
    ].join('-');
}

function formatDate(date: string | null): string {
    if (!date) {
        return '—';
    }

    return new Date(date).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}
