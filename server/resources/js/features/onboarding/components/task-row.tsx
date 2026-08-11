import {
    CircleDashed,
    Clock3,
    MoreHorizontal,
    Pencil,
    PlayCircle,
    SkipForward,
    Trash2,
} from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import { TASK_STATUS_LABELS } from '../constants';
import type { OnboardingTask, TaskStatus } from '../types';

type Props = {
    task: OnboardingTask;
    canManage: boolean;
    onToggle: (task: OnboardingTask, status: TaskStatus) => void;
    onEdit: (task: OnboardingTask) => void;
    onDelete: (task: OnboardingTask) => void;
};

export function TaskRow({
    task,
    canManage,
    onToggle,
    onEdit,
    onDelete,
}: Props) {
    const done = task.status === 'done';
    const skipped = task.status === 'skipped';

    return (
        <div className="flex items-start gap-3 px-3 py-2.5">
            <Checkbox
                checked={done}
                disabled={!canManage}
                onCheckedChange={(checked) =>
                    onToggle(task, checked ? 'done' : 'pending')
                }
                className="mt-0.5"
                aria-label={done ? 'Mark not done' : 'Mark done'}
            />

            <div className="min-w-0 flex-1">
                <p
                    className={cn(
                        'text-sm font-medium',
                        (done || skipped) &&
                            'text-muted-foreground line-through',
                    )}
                >
                    {task.title}
                </p>
                {task.description && (
                    <p className="mt-0.5 text-xs text-muted-foreground">
                        {task.description}
                    </p>
                )}
                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                    {task.due_date && (
                        <span
                            className={cn(
                                'inline-flex items-center gap-1',
                                task.is_overdue &&
                                    'font-medium text-rose-600 dark:text-rose-400',
                            )}
                        >
                            <Clock3 className="size-3" />
                            {formatDate(task.due_date)}
                        </span>
                    )}
                    {task.assignee && (
                        <span className="inline-flex items-center gap-1">
                            <CircleDashed className="size-3" />
                            {task.assignee.full_name}
                        </span>
                    )}
                    {(skipped || task.status === 'in_progress') && (
                        <span className="rounded-full bg-muted px-1.5 py-0.5 font-medium text-foreground/70">
                            {TASK_STATUS_LABELS[task.status]}
                        </span>
                    )}
                </div>
            </div>

            {canManage && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted"
                            aria-label="Task actions"
                        >
                            <MoreHorizontal className="size-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-44">
                        {task.status !== 'in_progress' && !done && (
                            <DropdownMenuItem
                                onSelect={() => onToggle(task, 'in_progress')}
                            >
                                <PlayCircle className="size-4" />
                                Mark in progress
                            </DropdownMenuItem>
                        )}
                        {!skipped ? (
                            <DropdownMenuItem
                                onSelect={() => onToggle(task, 'skipped')}
                            >
                                <SkipForward className="size-4" />
                                Skip
                            </DropdownMenuItem>
                        ) : (
                            <DropdownMenuItem
                                onSelect={() => onToggle(task, 'pending')}
                            >
                                <CircleDashed className="size-4" />
                                Un-skip
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem onSelect={() => onEdit(task)}>
                            <Pencil className="size-4" />
                            Edit
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => onDelete(task)}
                        >
                            <Trash2 className="size-4" />
                            Delete
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </div>
    );
}

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}
