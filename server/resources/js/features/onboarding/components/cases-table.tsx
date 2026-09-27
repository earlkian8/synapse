import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUp,
    ChevronsUpDown,
    UserRoundCheck,
} from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import {
    Table,
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
    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <Table>
                <TableHeader className="bg-muted/40">
                    <TableRow className="hover:bg-transparent">
                        <SortHeader
                            column="employee"
                            filters={filters}
                            onSort={onSort}
                            className="pl-4"
                        >
                            Employee
                        </SortHeader>
                        <TableHead className="h-9">Department</TableHead>
                        <TableHead className="h-9">Status</TableHead>
                        <TableHead className="h-9 w-48">Checklist</TableHead>
                        <TableHead className="h-9 text-right">
                            Overdue
                        </TableHead>
                        <SortHeader
                            column="start_date"
                            filters={filters}
                            onSort={onSort}
                        >
                            Started
                        </SortHeader>
                        <SortHeader
                            column="target_end_date"
                            filters={filters}
                            onSort={onSort}
                        >
                            Target
                        </SortHeader>
                        <TableHead className="h-9 w-10 pr-4" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {cases.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={8} className="py-12">
                                <Empty filtered={filtered} />
                            </TableCell>
                        </TableRow>
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
            </Table>
        </div>
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

    const open = (event: MouseEvent<HTMLTableRowElement>) => {
        if (
            (event.target as HTMLElement).closest(
                'a, button, [role="menuitem"]',
            )
        ) {
            return;
        }

        router.visit(href);
    };

    return (
        <TableRow onClick={open} className="cursor-pointer">
            <TableCell className="py-2 pl-4">
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
            <TableCell className="py-2">
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
            <TableCell className="py-2">
                <CaseStatusBadge status={item.status} />
            </TableCell>
            <TableCell className="py-2">
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
            <TableCell className="py-2 text-right text-sm tabular-nums">
                {inFlight && progress.overdue > 0 ? (
                    <span className="inline-flex items-center gap-1 font-medium text-rose-600 dark:text-rose-400">
                        <AlertTriangle className="size-3.5" />
                        {progress.overdue}
                    </span>
                ) : (
                    <span className="text-muted-foreground">0</span>
                )}
            </TableCell>
            <TableCell className="py-2 text-sm whitespace-nowrap text-muted-foreground">
                {formatDate(item.start_date)}
            </TableCell>
            <TableCell className="py-2 text-sm whitespace-nowrap">
                <TargetDate item={item} />
            </TableCell>
            <TableCell className="py-2 pr-4 text-right">
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

function SortHeader({
    column,
    filters,
    onSort,
    className,
    children,
}: {
    column: CaseSort;
    filters: CaseFilters;
    onSort: (column: CaseSort) => void;
    className?: string;
    children: ReactNode;
}) {
    const active = filters.sort === column;

    return (
        <TableHead className={cn('h-9', className)}>
            <button
                type="button"
                onClick={() => onSort(column)}
                className={cn(
                    // A button resets text-transform; match the other headers.
                    'inline-flex items-center gap-1 tracking-wide uppercase transition-colors hover:text-foreground',
                    active && 'text-foreground',
                )}
            >
                {children}
                {active ? (
                    filters.direction === 'asc' ? (
                        <ArrowUp className="size-3.5" />
                    ) : (
                        <ArrowDown className="size-3.5" />
                    )
                ) : (
                    <ChevronsUpDown className="size-3.5 opacity-40" />
                )}
            </button>
        </TableHead>
    );
}

function Empty({ filtered }: { filtered: boolean }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 text-center">
            <span className="flex size-10 items-center justify-center rounded-full bg-muted">
                <UserRoundCheck className="size-5 text-muted-foreground" />
            </span>
            <p className="text-sm font-medium">
                {filtered ? 'Nobody matches' : 'Nobody is onboarding here yet'}
            </p>
            <p className="max-w-xs text-sm text-muted-foreground">
                {filtered
                    ? 'Try adjusting the search or filters.'
                    : 'New hires on this program land here automatically — or start onboarding for someone.'}
            </p>
        </div>
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
