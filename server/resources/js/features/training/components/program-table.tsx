import { Link } from '@inertiajs/react';
import {
    ArchiveRestore,
    Eye,
    GraduationCap,
    Trash2,
    Trophy,
    Users,
} from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    rowOpens,
    SortableHead,
    TableCard,
} from '@/components/data-table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
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

export type ProgramSort =
    'name' | 'schedule' | 'seats' | 'completed' | 'status';

type Props = {
    programs: TrainingProgram[];
    sort: ProgramSort;
    direction: 'asc' | 'desc';
    onSort: (key: ProgramSort) => void;
    canManage: boolean;
    /** The list is the archive: rows restore or delete rather than open. */
    archived: boolean;
    filtered: boolean;
    onRestore: (program: TrainingProgram) => void;
    onForceDelete: (program: TrainingProgram) => void;
};

/**
 * Training programs: who runs them, when, how full they are, how many finished,
 * and where each stands. A row opens the program; archived ones are restored or
 * deleted from their menu.
 */
export function ProgramTable({
    programs,
    sort,
    direction,
    onSort,
    canManage,
    archived,
    filtered,
    onRestore,
    onForceDelete,
}: Props) {
    const sortable = (key: ProgramSort) => ({
        active: sort === key,
        direction,
        onSort: () => onSort(key),
    });

    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <SortableHead {...sortable('name')}>
                            Program
                        </SortableHead>
                        <SortableHead {...sortable('schedule')}>
                            Schedule
                        </SortableHead>
                        <SortableHead {...sortable('seats')} align="right">
                            Seats
                        </SortableHead>
                        <SortableHead {...sortable('completed')} align="right">
                            Completed
                        </SortableHead>
                        <SortableHead {...sortable('status')}>
                            Status
                        </SortableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {programs.length === 0 && (
                        <EmptyTableRow
                            colSpan={6}
                            icon={GraduationCap}
                            title={
                                archived
                                    ? 'Nothing archived'
                                    : filtered
                                      ? 'No programs match'
                                      : 'No training programs yet'
                            }
                            description={
                                archived
                                    ? 'Archived programs appear here.'
                                    : filtered
                                      ? 'Try another status, or clear the search.'
                                      : canManage
                                        ? 'Create one with "New program", then enroll employees in it.'
                                        : 'No training programs have been set up yet.'
                            }
                        />
                    )}

                    {programs.map((program) => {
                        const href = trainingRoutes.show(program.hashid);
                        const opens = rowOpens(href);

                        return (
                            <TableRow
                                key={program.id}
                                {...(archived ? {} : opens)}
                                className={cn(
                                    !archived && opens.className,
                                    archived && 'text-muted-foreground',
                                )}
                            >
                                <TableCell>
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <span
                                            className={cn(
                                                'flex size-8 shrink-0 items-center justify-center rounded-lg',
                                                archived
                                                    ? 'bg-muted text-muted-foreground'
                                                    : 'bg-[#0ABFBF]/10 text-[#0ABFBF]',
                                            )}
                                        >
                                            <GraduationCap className="size-4" />
                                        </span>
                                        <div className="min-w-0">
                                            {archived ? (
                                                <p className="max-w-64 truncate text-sm font-medium">
                                                    {program.name}
                                                </p>
                                            ) : (
                                                <Link
                                                    href={href}
                                                    className="block max-w-64 truncate text-sm font-medium hover:text-[#0ABFBF]"
                                                >
                                                    {program.name}
                                                </Link>
                                            )}
                                            <p className="max-w-64 truncate text-xs text-muted-foreground">
                                                {program.provider ?? 'In-house'}
                                            </p>
                                        </div>
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
                                    {archived ? (
                                        <span className="inline-flex items-center rounded-full border border-border bg-muted px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                                            Archived
                                        </span>
                                    ) : (
                                        <ProgramStatusBadge
                                            status={program.status}
                                        />
                                    )}
                                </TableCell>
                                <TableCell className="text-right">
                                    {(!archived || canManage) && (
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <RowMenuTrigger
                                                    label={`Actions for ${program.name}`}
                                                />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                className="w-48"
                                            >
                                                {archived ? (
                                                    <>
                                                        <DropdownMenuItem
                                                            onSelect={() =>
                                                                onRestore(
                                                                    program,
                                                                )
                                                            }
                                                        >
                                                            <ArchiveRestore className="size-4" />
                                                            Restore
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            onSelect={() =>
                                                                onForceDelete(
                                                                    program,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 className="size-4" />
                                                            Delete permanently
                                                        </DropdownMenuItem>
                                                    </>
                                                ) : (
                                                    <DropdownMenuItem asChild>
                                                        <Link href={href}>
                                                            <Eye className="size-4" />
                                                            Open program
                                                        </Link>
                                                    </DropdownMenuItem>
                                                )}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    )}
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}
