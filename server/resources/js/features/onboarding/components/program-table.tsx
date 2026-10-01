import {
    ListChecks,
    MoreHorizontal,
    Pencil,
    Trash2,
    Users,
} from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { EMPLOYMENT_TYPE_LABELS } from '../constants';
import type { OnboardingProgram } from '../types';

type Props = {
    programs: OnboardingProgram[];
    canManage: boolean;
    onEdit: (program: OnboardingProgram) => void;
    onDelete: (program: OnboardingProgram) => void;
};

/** Onboarding programs as a dense, scannable table — the default layout. */
export function ProgramTable({ programs, canManage, onEdit, onDelete }: Props) {
    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <div className="overflow-x-auto">
                <Table>
                    <TableHeader className="bg-muted/40">
                        <TableRow className="hover:bg-transparent">
                            <TableHead>Program</TableHead>
                            <TableHead>Targets</TableHead>
                            <TableHead className="text-right">Tasks</TableHead>
                            <TableHead className="text-right">Used</TableHead>
                            <TableHead>Status</TableHead>
                            {canManage && <TableHead className="w-10 pr-4" />}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {programs.map((program) => {
                            const targeting = [
                                program.department?.name,
                                program.employment_type
                                    ? EMPLOYMENT_TYPE_LABELS[
                                          program.employment_type
                                      ]
                                    : null,
                            ].filter(Boolean);

                            return (
                                <TableRow
                                    key={program.id}
                                    className={cn(
                                        !program.is_active && 'opacity-60',
                                    )}
                                >
                                    <TableCell>
                                        <div className="flex min-w-0 flex-col">
                                            <span className="text-sm font-medium">
                                                {program.name}
                                            </span>
                                            {program.description && (
                                                <span className="line-clamp-1 max-w-xs text-xs text-muted-foreground">
                                                    {program.description}
                                                </span>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-sm text-muted-foreground">
                                        {targeting.length > 0
                                            ? targeting.join(' · ')
                                            : 'All new hires'}
                                    </TableCell>
                                    <TableCell className="text-right text-sm tabular-nums">
                                        <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                            <ListChecks className="size-3.5" />
                                            {program.tasks_count ?? 0}
                                        </span>
                                    </TableCell>
                                    <TableCell className="text-right text-sm tabular-nums">
                                        <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                                            <Users className="size-3.5" />
                                            {program.cases_count ?? 0}
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            {program.is_default && (
                                                <span className="rounded-full border border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-1.5 py-0.5 text-[10px] font-medium text-[#0ABFBF]">
                                                    Default
                                                </span>
                                            )}
                                            <span
                                                className={cn(
                                                    'rounded-full border px-1.5 py-0.5 text-[10px] font-medium',
                                                    program.is_active
                                                        ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                                                        : 'border-border bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {program.is_active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </span>
                                        </div>
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="pr-4 text-right">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <button
                                                        type="button"
                                                        className="ml-auto rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted"
                                                        aria-label="Program actions"
                                                    >
                                                        <MoreHorizontal className="size-4" />
                                                    </button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent
                                                    align="end"
                                                    className="w-40"
                                                >
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            onEdit(program)
                                                        }
                                                    >
                                                        <Pencil className="size-4" />
                                                        Edit
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onSelect={() =>
                                                            onDelete(program)
                                                        }
                                                    >
                                                        <Trash2 className="size-4" />
                                                        Delete
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    )}
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
