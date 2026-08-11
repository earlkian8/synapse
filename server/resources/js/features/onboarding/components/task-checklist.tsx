import { useMemo } from 'react';
import { cn } from '@/lib/utils';
import { CATEGORY_META, CATEGORY_ORDER } from '../constants';
import type { OnboardingTask, TaskCategory, TaskStatus } from '../types';
import { TaskRow } from './task-row';

type Props = {
    tasks: OnboardingTask[];
    canManage: boolean;
    onToggle: (task: OnboardingTask, status: TaskStatus) => void;
    onEdit: (task: OnboardingTask) => void;
    onDelete: (task: OnboardingTask) => void;
};

export function TaskChecklist({
    tasks,
    canManage,
    onToggle,
    onEdit,
    onDelete,
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

    if (tasks.length === 0) {
        return (
            <div className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-12 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                No tasks yet. Add the first checklist item to get started.
            </div>
        );
    }

    return (
        <div className="space-y-5">
            {groups.map(({ category, tasks: groupTasks }) => {
                const meta = CATEGORY_META[category];
                const Icon = meta.icon;
                const resolved = groupTasks.filter((t) => t.is_resolved).length;

                return (
                    <div
                        key={category}
                        className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border"
                    >
                        <div className="flex items-center gap-2.5 border-b border-border px-3 py-2.5">
                            <span
                                className={cn(
                                    'flex size-7 items-center justify-center rounded-lg',
                                    meta.accent,
                                )}
                            >
                                <Icon className="size-4" />
                            </span>
                            <span className="text-sm font-semibold">
                                {meta.label}
                            </span>
                            <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                                {resolved}/{groupTasks.length}
                            </span>
                        </div>
                        <div className="divide-y divide-border">
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
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
