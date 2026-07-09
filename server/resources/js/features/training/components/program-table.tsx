import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronsUpDown, Trophy, Users } from 'lucide-react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { formatDateRange } from '../constants';
import { trainingRoutes } from '../routes';
import type { TrainingProgram } from '../types';
import { ProgramStatusBadge } from './training-status-badge';

export type ProgramSort = 'name' | 'schedule' | 'seats' | 'completed' | 'status';

type Props = {
    programs: TrainingProgram[];
    sort: ProgramSort;
    direction: 'asc' | 'desc';
    onSort: (key: ProgramSort) => void;
};

/** Training programs as a dense, sortable table — the default layout. */
export function ProgramTable({ programs, sort, direction, onSort }: Props) {
    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader className="bg-muted/40">
                        <TableRow className="hover:bg-transparent">
                            <SortHead
                                label="Program"
                                sortKey="name"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                            />
                            <SortHead
                                label="Schedule"
                                sortKey="schedule"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                            />
                            <SortHead
                                label="Seats"
                                sortKey="seats"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                                align="right"
                            />
                            <SortHead
                                label="Completed"
                                sortKey="completed"
                                sort={sort}
                                direction={direction}
                                onSort={onSort}
                                align="right"
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
                        {programs.map((program) => (
                            <TableRow
                                key={program.id}
                                onClick={() =>
                                    router.get(
                                        trainingRoutes.show(program.hashid),
                                    )
                                }
                                className="cursor-pointer"
                            >
                                <TableCell>
                                    <div className="flex min-w-0 flex-col">
                                        <span className="text-sm font-medium">
                                            {program.name}
                                        </span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            {program.provider ?? 'In-house'}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {formatDateRange(
                                        program.start_date,
                                        program.end_date,
                                    )}
                                </TableCell>
                                <TableCell className="text-right text-sm tabular-nums">
                                    <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                        <Users className="size-3.5" />
                                        {program.capacity === null
                                            ? `${program.active_count}`
                                            : `${program.active_count} / ${program.capacity}`}
                                    </span>
                                </TableCell>
                                <TableCell className="text-right text-sm tabular-nums">
                                    <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                        <Trophy className="size-3.5" />
                                        {program.completed_count}
                                    </span>
                                </TableCell>
                                <TableCell>
                                    <ProgramStatusBadge
                                        status={program.status}
                                    />
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
    sortKey: ProgramSort;
    sort: ProgramSort;
    direction: 'asc' | 'desc';
    onSort: (key: ProgramSort) => void;
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
