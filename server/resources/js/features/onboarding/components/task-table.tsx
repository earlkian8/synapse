import {
    CircleDashed,
    ListChecks,
    Pencil,
    PlayCircle,
    Plus,
    SkipForward,
    Trash2,
} from 'lucide-react';
import { Fragment, useMemo } from 'react';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    rowOpens,
    TableCard,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import {
    CATEGORY_META,
    CATEGORY_ORDER,
    TASK_STATUS_LABELS,
    TASK_STATUS_STYLES,
} from '../constants';
import type { OnboardingTask, TaskCategory, TaskStatus } from '../types';

type Props = {
    tasks: OnboardingTask[];
    canManage: boolean;
    onToggle: (task: OnboardingTask, status: TaskStatus) => void;
    onEdit: (task: OnboardingTask) => void;
    onDelete: (task: OnboardingTask) => void;
    onAdd: () => void;
};

const COLUMNS = 7;

/**
 * The onboarding checklist as one table: a header row per category, then a
 * row per task — tick it off, see who owns it and when it is due. For someone
 * who manages onboarding, a row opens the task to edit.
 */
export function TaskTable({
    tasks,
    canManage,
    onToggle,
    onEdit,
    onDelete,
    onAdd,
}: Props) {
    const groups = useMemo(() => {
        const byCategory = new Map<TaskCategory, OnboardingTask[]>();

        for (const task of tasks) {
            const list = byCategory.get(task.category) ?? [];
            list.push(task);
            byCategory.set(task.category, list);
        }

        return CATEGORY_ORDER.filter((category) =>
            byCategory.has(category),
        ).map((category) => ({
            category,
            tasks: byCategory.get(category)!,
        }));
    }, [tasks]);

    return (
        <TableCard
            title="Checklist"
            count={tasks.length}
            description="Grouped by kind of task. Tick a task off once it is done."
        >
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-10">
                            <span className="sr-only">Done</span>
                        </TableHead>
                        <TableHead>Task</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Owner</TableHead>
                        <TableHead>Due</TableHead>
                        <TableHead>Completed</TableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {tasks.length === 0 && (
                        <EmptyTableRow
                            colSpan={COLUMNS}
                            icon={ListChecks}
                            title="No tasks yet"
                            description="Add the first checklist item to get started."
                            action={
                                canManage && (
                                    <Button size="sm" onClick={onAdd}>
                                        <Plus className="size-4" />
                                        Add task
                                    </Button>
                                )
                            }
                        />
                    )}

                    {groups.map(({ category, tasks: groupTasks }) => {
                        const meta = CATEGORY_META[category];
                        const Icon = meta.icon;
                        const resolved = groupTasks.filter(
                            (task) => task.is_resolved,
                        ).length;

                        return (
                            <Fragment key={category}>
                                <TableRow className="bg-muted/30 hover:bg-muted/30">
                                    <TableCell
                                        colSpan={COLUMNS}
                                        className="py-1.5"
                                    >
                                        <span className="flex items-center gap-2">
                                            <span
                                                className={cn(
                                                    'flex size-5 items-center justify-center rounded-md',
                                                    meta.accent,
                                                )}
                                            >
                                                <Icon className="size-3" />
                                            </span>
                                            <span className="text-xs font-semibold">
                                                {meta.label}
                                            </span>
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {resolved}/{groupTasks.length}{' '}
                                                done
                                            </span>
                                        </span>
                                    </TableCell>
                                </TableRow>
                                {groupTasks.map((task) => (
                                    <TaskRow
                                        key={task.id}
                                        task={task}
                                        canManage={canManage}
                                        onToggle={onToggle}
                                        onEdit={onEdit}
                                        onDelete={onDelete}
                                    />
                                ))}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}

function TaskRow({
    task,
    canManage,
    onToggle,
    onEdit,
    onDelete,
}: {
    task: OnboardingTask;
    canManage: boolean;
    onToggle: (task: OnboardingTask, status: TaskStatus) => void;
    onEdit: (task: OnboardingTask) => void;
    onDelete: (task: OnboardingTask) => void;
}) {
    const done = task.status === 'done';
    const skipped = task.status === 'skipped';

    return (
        <TableRow {...(canManage ? rowOpens(() => onEdit(task)) : {})}>
            <TableCell>
                <Checkbox
                    checked={done}
                    disabled={!canManage}
                    onCheckedChange={(checked) =>
                        onToggle(task, checked ? 'done' : 'pending')
                    }
                    aria-label={
                        done
                            ? `Mark “${task.title}” not done`
                            : `Mark “${task.title}” done`
                    }
                />
            </TableCell>
            <TableCell className="min-w-56 whitespace-normal">
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
                    <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">
                        {task.description}
                    </p>
                )}
            </TableCell>
            <TableCell>
                <span
                    className={cn(
                        'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
                        TASK_STATUS_STYLES[task.status],
                    )}
                >
                    {TASK_STATUS_LABELS[task.status]}
                </span>
            </TableCell>
            <TableCell className="text-sm">
                {task.assignee?.full_name ?? (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>
            <TableCell className="text-sm whitespace-nowrap">
                {task.due_date ? (
                    <span
                        className={cn(
                            task.is_overdue
                                ? 'font-medium text-rose-600 dark:text-rose-400'
                                : 'text-muted-foreground',
                        )}
                        title={task.is_overdue ? 'Overdue' : undefined}
                    >
                        {formatDate(task.due_date)}
                        {task.is_overdue && (
                            <span className="sr-only"> (overdue)</span>
                        )}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>
            <TableCell className="text-xs whitespace-nowrap text-muted-foreground">
                {task.completed_human ? (
                    <>
                        {task.completed_human}
                        {task.completer && (
                            <span className="block">{task.completer}</span>
                        )}
                    </>
                ) : (
                    '—'
                )}
            </TableCell>
            <TableCell className="text-right">
                {canManage && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <RowMenuTrigger
                                label={`Actions for “${task.title}”`}
                            />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-44">
                            {task.status !== 'in_progress' && !done && (
                                <DropdownMenuItem
                                    onSelect={() =>
                                        onToggle(task, 'in_progress')
                                    }
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
            </TableCell>
        </TableRow>
    );
}

function formatDate(date: string): string {
    return new Date(date).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}
