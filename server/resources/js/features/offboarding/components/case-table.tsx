import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ChevronsUpDown,
    TriangleAlert,
} from 'lucide-react';
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
import { formatDate, TYPE_LABELS } from '../constants';
import { offboardingRoutes } from '../routes';
import type { OffboardingCase } from '../types';
import { CaseStatusBadge } from './case-status-badge';
import { ProgressBar } from './progress-bar';

export type CaseSort =
    | 'employee'
    | 'type'
    | 'last_day'
    | 'clearance'
    | 'status';

type Props = {
    cases: OffboardingCase[];
    sort: CaseSort;
    direction: 'asc' | 'desc';
    onSort: (key: CaseSort) => void;
};

/** Offboarding cases as a dense, sortable table — the default layout. */
export function CaseTable({ cases, sort, direction, onSort }: Props) {
    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader className="bg-muted/40">
                        <TableRow className="hover:bg-transparent">
                            <SortHead
                                label="Employee"
                                sortKey="employee"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                            />
                            <SortHead
                                label="Exit type"
                                sortKey="type"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                            />
                            <SortHead
                                label="Last day"
                                sortKey="last_day"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                            />
                            <SortHead
                                label="Clearance"
                                sortKey="clearance"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                            />
                            <SortHead
                                label="Status"
                                sortKey="status"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                            />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {cases.map((c) => (
                            <TableRow
                                key={c.id}
                                onClick={() =>
                                    router.get(offboardingRoutes.show(c.hashid))
                                }
                                className="cursor-pointer"
                            >
                                <TableCell>
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <PersonAvatar
                                            name={
                                                c.employee?.full_name ??
                                                'Unknown employee'
                                            }
                                            initials={
                                                c.employee?.initials ?? '?'
                                            }
                                            photo={c.employee?.photo}
                                            className="size-8"
                                            fallbackClassName="text-[10px]"
                                        />
                                        <div className="flex min-w-0 flex-col">
                                            <span className="truncate text-sm font-medium">
                                                {c.employee?.full_name ??
                                                    'Unknown employee'}
                                            </span>
                                            <span className="truncate text-xs text-muted-foreground">
                                                {c.employee?.position?.title ??
                                                    'No position'}
                                                {c.employee?.department
                                                    ? ` · ${c.employee.department.name}`
                                                    : ''}
                                            </span>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                    {TYPE_LABELS[c.type]}
                                </TableCell>
                                <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                    {formatDate(c.last_working_day)}
                                </TableCell>
                                <TableCell className="min-w-[10rem]">
                                    <div className="flex items-center gap-2">
                                        <ProgressBar
                                            percent={c.clearance.percent}
                                            muted={c.status === 'cancelled'}
                                            className="w-20"
                                        />
                                        <span className="text-xs text-muted-foreground tabular-nums">
                                            {c.clearance.cleared}/
                                            {c.clearance.total}
                                        </span>
                                        {c.clearance.flagged > 0 &&
                                            c.status !== 'cancelled' && (
                                                <span
                                                    className="inline-flex items-center gap-0.5 text-xs font-medium text-rose-600 tabular-nums dark:text-rose-400"
                                                    title={`${c.clearance.flagged} flagged`}
                                                >
                                                    <TriangleAlert className="size-3.5" />
                                                    {c.clearance.flagged}
                                                </span>
                                            )}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <CaseStatusBadge status={c.status} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}

function SortHead({
    label,
    sortKey,
    sort,
    direction,
    onSort,
    align = 'left',
}: {
    label: string;
    sortKey: CaseSort;
    sort: CaseSort;
    direction: 'asc' | 'desc';
    onSort: (key: CaseSort) => void;
    align?: 'left' | 'right';
}) {
    const active = sort === sortKey;

    return (
        <TableHead className={cn(align === 'right' && 'text-right')}>
            <button
                type="button"
                onClick={() => onSort(sortKey)}
                className={cn(
                    'inline-flex items-center gap-1 transition-colors hover:text-foreground',
                    align === 'right' && 'flex-row-reverse',
                    active && 'text-foreground',
                )}
            >
                {label}
                {active ? (
                    direction === 'asc' ? (
                        <ArrowUp className="size-3.5" />
                    ) : (
                        <ArrowDown className="size-3.5" />
                    )
                ) : (
                    <ChevronsUpDown className="size-3.5 opacity-50" />
                )}
            </button>
        </TableHead>
    );
}
