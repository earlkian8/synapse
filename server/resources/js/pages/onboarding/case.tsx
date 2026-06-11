import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    CalendarClock,
    CheckCircle2,
    ClipboardList,
    MoreHorizontal,
    Plus,
    RotateCcw,
    Settings2,
    Trash2,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { CaseSettingsSheet } from '@/features/onboarding/components/case-settings-sheet';
import { CaseStatusBadge } from '@/features/onboarding/components/case-status-badge';
import { ConfirmDialog } from '@/features/onboarding/components/confirm-dialog';
import { ProgressBar } from '@/features/onboarding/components/progress-bar';
import { TaskChecklist } from '@/features/onboarding/components/task-checklist';
import { TaskFormSheet } from '@/features/onboarding/components/task-form-sheet';
import { EMPLOYMENT_TYPE_LABELS } from '@/features/onboarding/constants';
import { onboardingRoutes } from '@/features/onboarding/routes';
import type {
    CasePageProps,
    OnboardingTask,
    TaskStatus,
} from '@/features/onboarding/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    run: () => void;
};

export default function OnboardingCasePage() {
    const { case: c, options, can } = usePage<CasePageProps>().props;
    const employee = c.employee;
    const tasks = c.tasks ?? [];

    const [taskSheetOpen, setTaskSheetOpen] = useState(false);
    const [editingTask, setEditingTask] = useState<OnboardingTask | null>(null);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    const withProcessing = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirmOpen(false);
        },
    };

    const openAddTask = () => {
        setEditingTask(null);
        setTaskSheetOpen(true);
    };

    const openEditTask = (task: OnboardingTask) => {
        setEditingTask(task);
        setTaskSheetOpen(true);
    };

    const toggleTask = (task: OnboardingTask, status: TaskStatus) =>
        router.patch(
            onboardingRoutes.taskStatus(task.id),
            { status },
            { preserveScroll: true },
        );

    const deleteTask = (task: OnboardingTask) =>
        askConfirm({
            title: `Delete "${task.title}"?`,
            description: 'This removes the task from the checklist.',
            confirmLabel: 'Delete task',
            destructive: true,
            run: () =>
                router.delete(onboardingRoutes.task(task.id), withProcessing),
        });

    const setStatus = (action: 'complete' | 'cancel' | 'reopen') =>
        router.patch(
            onboardingRoutes.status(c.hashid),
            { action },
            { preserveScroll: true },
        );

    const cancelCase = () =>
        askConfirm({
            title: 'Cancel this onboarding?',
            description:
                'The case is marked cancelled. You can reopen it later if needed.',
            confirmLabel: 'Cancel onboarding',
            destructive: true,
            run: () =>
                router.patch(
                    onboardingRoutes.status(c.hashid),
                    { action: 'cancel' },
                    withProcessing,
                ),
        });

    const removeCase = () =>
        askConfirm({
            title: 'Delete this onboarding?',
            description:
                'This permanently removes the onboarding case and its tasks. This cannot be undone.',
            confirmLabel: 'Delete onboarding',
            destructive: true,
            run: () => router.delete(onboardingRoutes.destroy(c.hashid)),
        });

    return (
        <>
            <Head title={`${employee?.full_name ?? 'Onboarding'} — Onboarding`} />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                {/* Header */}
                <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex items-start gap-3">
                        <Button
                            variant="outline"
                            size="icon"
                            className="size-9 shrink-0"
                            asChild
                        >
                            <Link
                                href={onboardingRoutes.index}
                                aria-label="Back to onboarding"
                            >
                                <ArrowLeft className="size-4" />
                            </Link>
                        </Button>
                        <Avatar className="size-11 rounded-lg ring-1 ring-border">
                            {employee?.photo && (
                                <AvatarImage
                                    src={employee.photo}
                                    alt={employee.full_name}
                                />
                            )}
                            <AvatarFallback className="rounded-lg bg-[#0F2044] text-sm font-semibold text-white">
                                {employee?.initials ?? '?'}
                            </AvatarFallback>
                        </Avatar>
                        <div>
                            <div className="flex items-center gap-2">
                                <h1 className="text-xl font-semibold tracking-tight">
                                    {employee?.full_name ?? 'Unknown employee'}
                                </h1>
                                <CaseStatusBadge status={c.status} />
                            </div>
                            <p className="mt-0.5 text-sm text-muted-foreground">
                                {employee?.position?.title ?? 'No position'}
                                {employee?.department
                                    ? ` · ${employee.department.name}`
                                    : ''}
                                {employee?.employment_type
                                    ? ` · ${EMPLOYMENT_TYPE_LABELS[employee.employment_type]}`
                                    : ''}
                            </p>
                        </div>
                    </div>

                    {can.manage && (
                        <div className="flex items-center gap-2">
                            <Button size="sm" onClick={openAddTask}>
                                <Plus className="size-4" />
                                Add task
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        className="size-9"
                                        aria-label="Onboarding actions"
                                    >
                                        <MoreHorizontal className="size-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-48">
                                    {c.is_active && (
                                        <DropdownMenuItem
                                            onSelect={() => setStatus('complete')}
                                        >
                                            <CheckCircle2 className="size-4" />
                                            Mark complete
                                        </DropdownMenuItem>
                                    )}
                                    {!c.is_active && (
                                        <DropdownMenuItem
                                            onSelect={() => setStatus('reopen')}
                                        >
                                            <RotateCcw className="size-4" />
                                            Reopen
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuItem
                                        onSelect={() => setSettingsOpen(true)}
                                    >
                                        <Settings2 className="size-4" />
                                        Edit details
                                    </DropdownMenuItem>
                                    {c.is_active && (
                                        <DropdownMenuItem onSelect={cancelCase}>
                                            <XCircle className="size-4" />
                                            Cancel onboarding
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={removeCase}
                                    >
                                        <Trash2 className="size-4" />
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    )}
                </div>

                {/* Summary band */}
                <div className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-sm font-medium">
                            {c.progress.resolved} of {c.progress.total} tasks
                            done
                        </span>
                        <span className="text-sm font-semibold tabular-nums text-[#0ABFBF]">
                            {c.progress.percent}%
                        </span>
                    </div>
                    <ProgressBar
                        percent={c.progress.percent}
                        muted={c.status === 'cancelled'}
                        className="mt-2"
                    />
                    <div className="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1.5 text-xs text-muted-foreground">
                        <Meta
                            icon={<CalendarClock className="size-3.5" />}
                            label="Started"
                            value={formatDate(c.start_date)}
                        />
                        <Meta
                            icon={<CalendarClock className="size-3.5" />}
                            label="Target"
                            value={formatDate(c.target_end_date)}
                        />
                        <Meta
                            icon={<ClipboardList className="size-3.5" />}
                            label="Program"
                            value={c.program?.name ?? 'None'}
                        />
                        {c.progress.overdue > 0 && (
                            <span className="font-medium text-rose-600 dark:text-rose-400">
                                {c.progress.overdue} overdue
                            </span>
                        )}
                    </div>
                    {c.notes && (
                        <p className="mt-3 rounded-lg bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
                            {c.notes}
                        </p>
                    )}
                </div>

                {/* Checklist */}
                <TaskChecklist
                    tasks={tasks}
                    canManage={can.manage}
                    onToggle={toggleTask}
                    onEdit={openEditTask}
                    onDelete={deleteTask}
                />
            </div>

            <TaskFormSheet
                task={editingTask}
                caseHashid={c.hashid}
                assignees={options.assignees}
                open={taskSheetOpen}
                onOpenChange={setTaskSheetOpen}
            />

            <CaseSettingsSheet
                case={c}
                open={settingsOpen}
                onOpenChange={setSettingsOpen}
            />

            {confirm && (
                <ConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    title={confirm.title}
                    description={confirm.description}
                    confirmLabel={confirm.confirmLabel}
                    destructive={confirm.destructive}
                    processing={processing}
                    onConfirm={confirm.run}
                />
            )}
        </>
    );
}

function Meta({
    icon,
    label,
    value,
}: {
    icon: ReactNode;
    label: string;
    value: string;
}) {
    return (
        <span className="inline-flex items-center gap-1.5">
            {icon}
            <span className="text-muted-foreground/70">{label}:</span>
            <span className="font-medium text-foreground">{value}</span>
        </span>
    );
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

OnboardingCasePage.layout = (props: CasePageProps) => ({
    breadcrumbs: [
        { title: 'Onboarding', href: onboardingRoutes.index },
        {
            title: props.case.employee?.full_name ?? 'Case',
            href: onboardingRoutes.show(props.case.hashid),
        },
    ],
});
