import { Link } from '@inertiajs/react';
import { Plus, TriangleAlert, UserRoundMinus } from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    rowOpens,
    SortableHead,
    TableCard,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { formatDate } from '../constants';
import { offboardingRoutes } from '../routes';
import type { OffboardingCase } from '../types';
import { CaseRowActions } from './case-row-actions';
import type { CaseRowHandlers } from './case-row-actions';
import { CaseStatusBadge } from './case-status-badge';
import { ProgressBar } from './progress-bar';
import { TypeBadge } from './type-badge';

export type CaseSort =
    'employee' | 'type' | 'last_day' | 'clearance' | 'status';

type Props = CaseRowHandlers & {
    cases: OffboardingCase[];
    sort: CaseSort;
    direction: 'asc' | 'desc';
    onSort: (key: CaseSort) => void;
    canManage: boolean;
    filtered: boolean;
    /** Offered in the empty state when nothing is filtered. */
    onStart?: () => void;
};

/**
 * Everyone leaving: the kind of exit, how far their clearance has come, what
 * is flagged, and their last day. A row opens that person's clearance.
 */
export function CaseTable({
    cases,
    sort,
    direction,
    onSort,
    canManage,
    filtered,
    onStart,
    ...handlers
}: Props) {
    const sortable = (key: CaseSort) => ({
        active: sort === key,
        direction,
        onSort: () => onSort(key),
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
                        <SortableHead {...sortable('type')}>
                            Exit type
                        </SortableHead>
                        <SortableHead {...sortable('status')}>
                            Status
                        </SortableHead>
                        <SortableHead
                            {...sortable('clearance')}
                            className="w-52"
                        >
                            Clearance
                        </SortableHead>
                        <SortableHead {...sortable('last_day')}>
                            Last day
                        </SortableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {cases.length === 0 && (
                        <EmptyTableRow
                            colSpan={7}
                            icon={UserRoundMinus}
                            title={
                                filtered
                                    ? 'No exits match'
                                    : 'Nobody is leaving right now'
                            }
                            description={
                                filtered
                                    ? 'Try adjusting the search or filters.'
                                    : 'When an employee is leaving, start their offboarding to generate a clearance checklist and track them to a clean exit.'
                            }
                            action={
                                !filtered &&
                                onStart && (
                                    <Button size="sm" onClick={onStart}>
                                        <Plus className="size-4" />
                                        Start offboarding
                                    </Button>
                                )
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
}: CaseRowHandlers & { item: OffboardingCase; canManage: boolean }) {
    const href = offboardingRoutes.show(item.hashid);
    const employee = item.employee;
    const { clearance } = item;
    const cancelled = item.status === 'cancelled';

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
                <TypeBadge type={item.type} />
            </TableCell>
            <TableCell>
                <CaseStatusBadge status={item.status} />
            </TableCell>
            <TableCell>
                <div className="flex items-center gap-2">
                    <ProgressBar
                        percent={clearance.percent}
                        muted={cancelled}
                        className="w-20"
                    />
                    <span className="text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                        {clearance.cleared}/{clearance.total}
                    </span>
                    {clearance.flagged > 0 && !cancelled && (
                        <span
                            className="inline-flex items-center gap-0.5 text-xs font-medium text-rose-600 tabular-nums dark:text-rose-400"
                            title={`${clearance.flagged} flagged`}
                        >
                            <TriangleAlert className="size-3.5" />
                            {clearance.flagged}
                            <span className="sr-only"> flagged</span>
                        </span>
                    )}
                </div>
            </TableCell>
            <TableCell className="text-sm whitespace-nowrap">
                <LastDay item={item} />
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

/** The last working day, flagged when an exit still in flight has gone past it. */
function LastDay({ item }: { item: OffboardingCase }) {
    if (item.status === 'completed') {
        return (
            <span className="text-muted-foreground">
                Done {formatDate(item.completed_at)}
            </span>
        );
    }

    if (!item.last_working_day) {
        return <span className="text-muted-foreground">Not set</span>;
    }

    const late = item.is_active && item.last_working_day < localToday();

    return (
        <span
            className={cn(
                late
                    ? 'font-medium text-rose-600 dark:text-rose-400'
                    : 'text-muted-foreground',
            )}
            title={late ? 'Past the last working day' : undefined}
        >
            {formatDate(item.last_working_day)}
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
