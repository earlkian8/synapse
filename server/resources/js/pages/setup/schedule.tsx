import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    CalendarDays,
    Clock,
    Pencil,
    Plus,
    Repeat,
    Trash2,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/features/payroll/components/confirm-dialog';
import { HolidayFormSheet } from '@/features/schedule-config/components/holiday-form-sheet';
import { HolidayTypeBadge } from '@/features/schedule-config/components/holiday-type-badge';
import { WorkScheduleFormSheet } from '@/features/schedule-config/components/work-schedule-form-sheet';
import {
    formatHolidayDate,
    formatScheduleHours,
} from '@/features/schedule-config/constants';
import { scheduleConfigRoutes } from '@/features/schedule-config/routes';
import type {
    Holiday,
    ScheduleSetupPageProps,
    WorkSchedule,
} from '@/features/schedule-config/types';
import { cn } from '@/lib/utils';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

export default function SetupSchedule() {
    const { schedules, archivedSchedules, holidays, archivedHolidays, can } =
        usePage<ScheduleSetupPageProps>().props;

    const [scheduleForm, setScheduleForm] = useState<{
        open: boolean;
        schedule: WorkSchedule | null;
    }>({ open: false, schedule: null });
    const [holidayForm, setHolidayForm] = useState<{
        open: boolean;
        holiday: Holiday | null;
    }>({ open: false, holiday: null });

    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const withProcessing = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => {
            setProcessing(false);
            setConfirmOpen(false);
        },
    };

    const askConfirm = (config: ConfirmConfig) => {
        setConfirm(config);
        setConfirmOpen(true);
    };

    return (
        <>
            <Head title="Work Schedule & Holidays" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Work Schedule &amp; Holidays
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        The shift patterns employees follow (read by Attendance)
                        and the holiday calendar (a holiday is not charged as a
                        leave day).
                    </p>
                </div>

                {/* Work schedules */}
                <ConfigSection
                    icon={Clock}
                    title="Work schedules"
                    subtitle="Shift patterns: hours, working days and lateness grace."
                    canManage={can.manage}
                    onNew={() =>
                        setScheduleForm({ open: true, schedule: null })
                    }
                    empty={schedules.length === 0}
                    emptyLabel="No work schedules yet."
                    archived={archivedSchedules}
                    onRestore={(s) =>
                        router.patch(
                            scheduleConfigRoutes.workSchedules.restore(
                                s.hashid,
                            ),
                            {},
                            { preserveScroll: true },
                        )
                    }
                    onForceDelete={(s) =>
                        askConfirm({
                            title: `Permanently delete "${s.name}"?`,
                            description:
                                'This cannot be undone. A schedule assigned to employees cannot be permanently deleted.',
                            confirmLabel: 'Delete permanently',
                            run: () =>
                                router.delete(
                                    scheduleConfigRoutes.workSchedules.forceDelete(
                                        s.hashid,
                                    ),
                                    withProcessing,
                                ),
                        })
                    }
                >
                    {schedules.map((schedule) => (
                        <div
                            key={schedule.id}
                            className="flex items-center gap-3 px-4 py-3"
                        >
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                <Clock className="size-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-medium">
                                    {schedule.name}
                                </p>
                                <p className="mt-0.5 truncate text-xs text-muted-foreground tabular-nums">
                                    {formatScheduleHours(schedule)}
                                </p>
                            </div>
                            <span className="hidden items-center gap-1 text-xs text-muted-foreground tabular-nums sm:flex">
                                <Users className="size-3.5" />
                                {schedule.employees_count}
                            </span>
                            {can.manage && (
                                <RowActions
                                    onEdit={() =>
                                        setScheduleForm({
                                            open: true,
                                            schedule,
                                        })
                                    }
                                    onArchive={() =>
                                        askConfirm({
                                            title: `Archive "${schedule.name}"?`,
                                            description:
                                                'It is hidden from new assignments; assigned employees keep it. You can restore it later.',
                                            confirmLabel: 'Archive',
                                            run: () =>
                                                router.delete(
                                                    scheduleConfigRoutes.workSchedules.destroy(
                                                        schedule.hashid,
                                                    ),
                                                    withProcessing,
                                                ),
                                        })
                                    }
                                />
                            )}
                        </div>
                    ))}
                </ConfigSection>

                {/* Holidays */}
                <ConfigSection
                    icon={CalendarDays}
                    title="Holidays"
                    subtitle="The company holiday calendar, by date."
                    canManage={can.manage}
                    onNew={() => setHolidayForm({ open: true, holiday: null })}
                    empty={holidays.length === 0}
                    emptyLabel="No holidays yet."
                    archived={archivedHolidays}
                    onRestore={(h) =>
                        router.patch(
                            scheduleConfigRoutes.holidays.restore(h.hashid),
                            {},
                            { preserveScroll: true },
                        )
                    }
                    onForceDelete={(h) =>
                        askConfirm({
                            title: `Permanently delete "${h.name}"?`,
                            description: 'This cannot be undone.',
                            confirmLabel: 'Delete permanently',
                            run: () =>
                                router.delete(
                                    scheduleConfigRoutes.holidays.forceDelete(
                                        h.hashid,
                                    ),
                                    withProcessing,
                                ),
                        })
                    }
                >
                    {holidays.map((holiday) => (
                        <div
                            key={holiday.id}
                            className="flex items-center gap-3 px-4 py-3"
                        >
                            <span className="flex size-9 shrink-0 flex-col items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0a8b91] dark:text-[#0ABFBF]">
                                <CalendarDays className="size-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="truncate text-sm font-medium">
                                        {holiday.name}
                                    </p>
                                    <HolidayTypeBadge type={holiday.type} />
                                    {holiday.is_recurring && (
                                        <span className="inline-flex items-center gap-1 text-[10px] font-medium text-muted-foreground">
                                            <Repeat className="size-3" />
                                            Yearly
                                        </span>
                                    )}
                                </div>
                                <p className="mt-0.5 truncate text-xs text-muted-foreground tabular-nums">
                                    {formatHolidayDate(holiday)}
                                </p>
                            </div>
                            {can.manage && (
                                <RowActions
                                    onEdit={() =>
                                        setHolidayForm({
                                            open: true,
                                            holiday,
                                        })
                                    }
                                    onArchive={() =>
                                        askConfirm({
                                            title: `Archive "${holiday.name}"?`,
                                            description:
                                                'It is removed from the calendar. You can restore it later.',
                                            confirmLabel: 'Archive',
                                            run: () =>
                                                router.delete(
                                                    scheduleConfigRoutes.holidays.destroy(
                                                        holiday.hashid,
                                                    ),
                                                    withProcessing,
                                                ),
                                        })
                                    }
                                />
                            )}
                        </div>
                    ))}
                </ConfigSection>
            </div>

            <WorkScheduleFormSheet
                schedule={scheduleForm.schedule}
                open={scheduleForm.open}
                onOpenChange={(open) =>
                    setScheduleForm((prev) => ({ ...prev, open }))
                }
            />
            <HolidayFormSheet
                holiday={holidayForm.holiday}
                open={holidayForm.open}
                onOpenChange={(open) =>
                    setHolidayForm((prev) => ({ ...prev, open }))
                }
            />

            {confirm && (
                <ConfirmDialog
                    open={confirmOpen}
                    onOpenChange={setConfirmOpen}
                    title={confirm.title}
                    description={confirm.description}
                    confirmLabel={confirm.confirmLabel}
                    destructive
                    processing={processing}
                    onConfirm={confirm.run}
                />
            )}
        </>
    );
}

type ArchivedItem = { id: number; hashid: string; name: string };

function ConfigSection<T extends ArchivedItem>({
    icon: Icon,
    title,
    subtitle,
    canManage,
    onNew,
    empty,
    emptyLabel,
    archived,
    onRestore,
    onForceDelete,
    children,
}: {
    icon: React.ComponentType<{ className?: string }>;
    title: string;
    subtitle: string;
    canManage: boolean;
    onNew: () => void;
    empty: boolean;
    emptyLabel: string;
    archived: T[];
    onRestore: (item: T) => void;
    onForceDelete: (item: T) => void;
    children: ReactNode;
}) {
    const [showArchived, setShowArchived] = useState(false);

    return (
        <section className="flex flex-col gap-3">
            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2.5">
                    <span className="flex size-8 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                        <Icon className="size-4" />
                    </span>
                    <div>
                        <h2 className="text-sm font-semibold">{title}</h2>
                        <p className="text-xs text-muted-foreground">
                            {subtitle}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    {archived.length > 0 && (
                        <Button
                            variant={showArchived ? 'secondary' : 'ghost'}
                            size="sm"
                            onClick={() => setShowArchived((v) => !v)}
                            className={cn(
                                !showArchived && 'text-muted-foreground',
                            )}
                        >
                            <Archive className="size-4" />
                            Archived
                            <span className="ml-0.5 tabular-nums">
                                ({archived.length})
                            </span>
                        </Button>
                    )}
                    {canManage && (
                        <Button size="sm" onClick={onNew}>
                            <Plus className="size-4" />
                            New
                        </Button>
                    )}
                </div>
            </div>

            {empty ? (
                <p className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-8 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                    {emptyLabel}
                </p>
            ) : (
                <div className="divide-y divide-border overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                    {children}
                </div>
            )}

            {showArchived && archived.length > 0 && (
                <div className="space-y-2">
                    {archived.map((item) => (
                        <div
                            key={item.id}
                            className="flex items-center gap-3 rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-3 py-2.5 dark:border-sidebar-border"
                        >
                            <span className="min-w-0 flex-1 truncate text-sm font-medium text-muted-foreground">
                                {item.name}
                            </span>
                            {canManage && (
                                <>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => onRestore(item)}
                                    >
                                        <ArchiveRestore className="size-4" />
                                        Restore
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-8 text-muted-foreground hover:text-destructive"
                                        onClick={() => onForceDelete(item)}
                                        aria-label="Delete permanently"
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

function RowActions({
    onEdit,
    onArchive,
}: {
    onEdit: () => void;
    onArchive: () => void;
}) {
    return (
        <div className="flex items-center gap-1">
            <Button
                variant="ghost"
                size="icon"
                className="size-8"
                onClick={onEdit}
                aria-label="Edit"
            >
                <Pencil className="size-4" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                className="size-8 text-muted-foreground hover:text-destructive"
                onClick={onArchive}
                aria-label="Archive"
            >
                <Archive className="size-4" />
            </Button>
        </div>
    );
}

SetupSchedule.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Work Schedule & Holidays', href: '/setup/schedule' },
    ],
};
