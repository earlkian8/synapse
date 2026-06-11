import { ListChecks, MoreHorizontal, Pencil, Trash2, Users } from 'lucide-react';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { EMPLOYMENT_TYPE_LABELS } from '../constants';
import type { OnboardingProgram } from '../types';

type Props = {
    program: OnboardingProgram;
    canManage: boolean;
    onEdit: (program: OnboardingProgram) => void;
    onDelete: (program: OnboardingProgram) => void;
};

export function ProgramCard({ program, canManage, onEdit, onDelete }: Props) {
    const targeting = [
        program.department?.name,
        program.employment_type
            ? EMPLOYMENT_TYPE_LABELS[program.employment_type]
            : null,
    ].filter(Boolean);

    return (
        <div
            className={cn(
                'flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border',
                !program.is_active && 'opacity-70',
            )}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-1.5">
                        <span className="text-sm font-semibold">
                            {program.name}
                        </span>
                        {program.is_default && (
                            <span className="rounded-full border border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-1.5 py-0.5 text-[10px] font-medium text-[#0ABFBF]">
                                Default
                            </span>
                        )}
                        {!program.is_active && (
                            <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                                Inactive
                            </span>
                        )}
                    </div>
                    {program.description && (
                        <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">
                            {program.description}
                        </p>
                    )}
                </div>

                {canManage && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted"
                                aria-label="Program actions"
                            >
                                <MoreHorizontal className="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-40">
                            <DropdownMenuItem onSelect={() => onEdit(program)}>
                                <Pencil className="size-4" />
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => onDelete(program)}
                            >
                                <Trash2 className="size-4" />
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>

            <p className="text-xs text-muted-foreground">
                {targeting.length > 0
                    ? `For ${targeting.join(' · ')}`
                    : 'For all new hires'}
            </p>

            <div className="flex items-center gap-4 border-t border-border pt-3 text-xs text-muted-foreground">
                <span className="inline-flex items-center gap-1.5">
                    <ListChecks className="size-3.5" />
                    {program.tasks_count ?? 0} tasks
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <Users className="size-3.5" />
                    {program.cases_count ?? 0} used
                </span>
            </div>
        </div>
    );
}
