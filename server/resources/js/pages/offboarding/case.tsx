import { Head, router, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    CalendarX2,
    CheckCheck,
    CheckCircle2,
    ClipboardList,
    Download,
    ListPlus,
    MoreHorizontal,
    Plus,
    RotateCcw,
    Settings2,
    ShieldCheck,
    Trash2,
    XCircle,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { PageBody, PageHeader } from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { ApplyProgramDialog } from '@/features/offboarding/components/apply-program-dialog';
import { CaseSettingsDialog } from '@/features/offboarding/components/case-settings-dialog';
import { CaseStatusBadge } from '@/features/offboarding/components/case-status-badge';
import { ClearanceItemFormDialog } from '@/features/offboarding/components/clearance-item-form-dialog';
import { ClearanceTable } from '@/features/offboarding/components/clearance-table';
import { ConfirmDialog } from '@/features/offboarding/components/confirm-dialog';
import { ProgressBar } from '@/features/offboarding/components/progress-bar';
import { TypeBadge } from '@/features/offboarding/components/type-badge';
import {
    DERIVED_CLEARANCE_LABELS,
    EMPLOYMENT_TYPE_LABELS,
    formatDate,
    TYPE_LABELS,
} from '@/features/offboarding/constants';
import { offboardingRoutes } from '@/features/offboarding/routes';
import type {
    CasePageProps,
    ClearanceItem,
    ClearanceStatus,
} from '@/features/offboarding/types';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    destructive?: boolean;
    run: () => void;
};

/** The employment status this exit type lands the employee in on completion. */
function separationLabel(type: CasePageProps['case']['type']): string {
    return type === 'termination' ? 'terminated' : 'resigned';
}

export default function OffboardingCasePage() {
    const { case: c, options, can } = usePage<CasePageProps>().props;
    const employee = c.employee;
    const items = c.items ?? [];

    const [itemFormOpen, setItemFormOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<ClearanceItem | null>(null);
    const [settingsOpen, setSettingsOpen] = useState(false);
    const [applyOpen, setApplyOpen] = useState(false);
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

    const openAddItem = () => {
        setEditingItem(null);
        setItemFormOpen(true);
    };

    const openEditItem = (item: ClearanceItem) => {
        setEditingItem(item);
        setItemFormOpen(true);
    };

    const toggleItem = (item: ClearanceItem, status: ClearanceStatus) =>
        router.patch(
            offboardingRoutes.itemStatus(item.id),
            { status },
            { preserveScroll: true },
        );

    const deleteItem = (item: ClearanceItem) =>
        askConfirm({
            title: `Delete "${item.item}"?`,
            description: 'This removes the item from the clearance checklist.',
            confirmLabel: 'Delete item',
            destructive: true,
            run: () =>
                router.delete(offboardingRoutes.item(item.id), withProcessing),
        });

    const reopenCase = () =>
        router.patch(
            offboardingRoutes.status(c.hashid),
            { action: 'reopen' },
            { preserveScroll: true },
        );

    /** Sign off one department group's pending items (null = unassigned). */
    const clearGroup = (departmentId: number | null, name: string) =>
        askConfirm({
            title: `Clear all pending items for ${name}?`,
            description:
                'Every pending item in this group is signed off under your name. Flagged items are left untouched.',
            confirmLabel: 'Clear all',
            run: () =>
                router.patch(
                    offboardingRoutes.bulkClear(c.hashid),
                    departmentId === null
                        ? { scope: 'unassigned' }
                        : { scope: 'department', department_id: departmentId },
                    withProcessing,
                ),
        });

    /** Sign off every pending item on the case in one go. */
    const clearAllPending = () =>
        askConfirm({
            title: `Clear all ${c.clearance.pending} pending items?`,
            description:
                'Every pending item on this checklist is signed off under your name. Flagged items are left untouched.',
            confirmLabel: 'Clear all pending',
            run: () =>
                router.patch(
                    offboardingRoutes.bulkClear(c.hashid),
                    { scope: 'all' },
                    withProcessing,
                ),
        });

    const completeCase = () => {
        const outstanding = c.clearance.total - c.clearance.cleared;

        askConfirm({
            title: 'Complete this offboarding?',
            description: (
                <>
                    The employee will be marked{' '}
                    <span className="font-medium text-foreground">
                        {separationLabel(c.type)}
                    </span>{' '}
                    and their last working day finalised.
                    {outstanding > 0 && (
                        <>
                            {' '}
                            <span className="font-medium text-rose-600 dark:text-rose-400">
                                {outstanding} clearance item
                                {outstanding === 1 ? '' : 's'}
                            </span>{' '}
                            still pending.
                        </>
                    )}
                </>
            ),
            confirmLabel: 'Complete exit',
            run: () =>
                router.patch(
                    offboardingRoutes.status(c.hashid),
                    { action: 'complete' },
                    withProcessing,
                ),
        });
    };

    const cancelCase = () =>
        askConfirm({
            title: 'Cancel this offboarding?',
            description:
                'The exit is marked cancelled and the employee returns to active. You can reopen it later if needed.',
            confirmLabel: 'Cancel offboarding',
            destructive: true,
            run: () =>
                router.patch(
                    offboardingRoutes.status(c.hashid),
                    { action: 'cancel' },
                    withProcessing,
                ),
        });

    const removeCase = () =>
        askConfirm({
            title: 'Delete this offboarding?',
            description:
                'This permanently removes the exit case and its clearance items. This cannot be undone.',
            confirmLabel: 'Delete offboarding',
            destructive: true,
            run: () => router.delete(offboardingRoutes.destroy(c.hashid)),
        });

    return (
        <>
            <Head
                title={`${employee?.full_name ?? 'Offboarding'} — Offboarding`}
            />

            <PageBody>
                <PageHeader
                    back={{
                        href: offboardingRoutes.index,
                        label: 'Back to offboarding',
                    }}
                    leading={
                        <PersonAvatar
                            name={employee?.full_name ?? 'Unknown employee'}
                            initials={employee?.initials ?? '?'}
                            photo={employee?.photo}
                            className="size-11"
                            fallbackClassName="text-sm"
                        />
                    }
                    title={employee?.full_name ?? 'Unknown employee'}
                    badges={
                        <>
                            <CaseStatusBadge status={c.status} />
                            <TypeBadge type={c.type} />
                        </>
                    }
                    description={[
                        employee?.position?.title ?? 'No position',
                        employee?.department?.name,
                        employee?.employment_type
                            ? EMPLOYMENT_TYPE_LABELS[employee.employment_type]
                            : null,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        <>
                            <Button variant="outline" size="sm" asChild>
                                <a
                                    href={offboardingRoutes.clearanceExport(
                                        c.hashid,
                                    )}
                                >
                                    <Download className="size-4" />
                                    Export sheet
                                </a>
                            </Button>
                            {can.manage && (
                                <>
                                    <Button size="sm" onClick={openAddItem}>
                                        <Plus className="size-4" />
                                        Add item
                                    </Button>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                variant="outline"
                                                size="icon"
                                                className="size-9"
                                                aria-label="Offboarding actions"
                                            >
                                                <MoreHorizontal className="size-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            className="w-48"
                                        >
                                            {c.is_active && (
                                                <DropdownMenuItem
                                                    onSelect={completeCase}
                                                >
                                                    <CheckCircle2 className="size-4" />
                                                    Complete exit
                                                </DropdownMenuItem>
                                            )}
                                            {!c.is_active && (
                                                <DropdownMenuItem
                                                    onSelect={reopenCase}
                                                >
                                                    <RotateCcw className="size-4" />
                                                    Reopen
                                                </DropdownMenuItem>
                                            )}
                                            {c.is_active &&
                                                options.programs.length > 0 && (
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            setApplyOpen(true)
                                                        }
                                                    >
                                                        <ListPlus className="size-4" />
                                                        Add from template
                                                    </DropdownMenuItem>
                                                )}
                                            {c.is_active &&
                                                c.clearance.pending > 0 && (
                                                    <DropdownMenuItem
                                                        onSelect={
                                                            clearAllPending
                                                        }
                                                    >
                                                        <CheckCheck className="size-4" />
                                                        Clear all pending
                                                    </DropdownMenuItem>
                                                )}
                                            <DropdownMenuItem
                                                onSelect={() =>
                                                    setSettingsOpen(true)
                                                }
                                            >
                                                <Settings2 className="size-4" />
                                                Edit details
                                            </DropdownMenuItem>
                                            {c.is_active && (
                                                <DropdownMenuItem
                                                    onSelect={cancelCase}
                                                >
                                                    <XCircle className="size-4" />
                                                    Cancel offboarding
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
                                </>
                            )}
                        </>
                    }
                />

                {/* Summary band */}
                <div className="rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 shadow-sm dark:border-sidebar-border">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="text-sm font-medium">
                            {c.clearance.cleared} of {c.clearance.total}{' '}
                            clearances signed off
                        </span>
                        <span className="inline-flex items-center gap-2">
                            <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                <ShieldCheck className="size-3.5" />
                                {DERIVED_CLEARANCE_LABELS[c.clearance.status]}
                            </span>
                            <span className="text-sm font-semibold text-[#0ABFBF] tabular-nums">
                                {c.clearance.percent}%
                            </span>
                        </span>
                    </div>
                    <ProgressBar
                        percent={c.clearance.percent}
                        muted={c.status === 'cancelled'}
                        className="mt-2"
                    />
                    <div className="mt-2.5 flex flex-wrap items-center gap-x-5 gap-y-1.5 text-xs text-muted-foreground">
                        <Meta
                            icon={<CalendarClock className="size-3.5" />}
                            label="Notice"
                            value={formatDate(c.notice_date)}
                        />
                        <Meta
                            icon={<CalendarX2 className="size-3.5" />}
                            label="Last day"
                            value={formatDate(c.last_working_day)}
                        />
                        <Meta
                            icon={<XCircle className="size-3.5" />}
                            label="Type"
                            value={TYPE_LABELS[c.type]}
                        />
                        {c.program && (
                            <Meta
                                icon={<ClipboardList className="size-3.5" />}
                                label="Template"
                                value={c.program}
                            />
                        )}
                        {c.clearance.flagged > 0 && (
                            <span className="font-medium text-rose-600 dark:text-rose-400">
                                {c.clearance.flagged} flagged
                            </span>
                        )}
                    </div>
                    {c.reason && (
                        <p className="mt-2.5 rounded-lg bg-muted/50 px-3 py-2 text-sm text-muted-foreground">
                            {c.reason}
                        </p>
                    )}
                </div>

                <ClearanceTable
                    items={items}
                    canManage={can.manage}
                    onToggle={toggleItem}
                    onEdit={openEditItem}
                    onDelete={deleteItem}
                    onAdd={openAddItem}
                    onClearGroup={clearGroup}
                />
            </PageBody>

            <ClearanceItemFormDialog
                item={editingItem}
                caseHashid={c.hashid}
                departments={options.departments}
                open={itemFormOpen}
                onOpenChange={setItemFormOpen}
            />

            <ApplyProgramDialog
                caseHashid={c.hashid}
                programs={options.programs}
                open={applyOpen}
                onOpenChange={setApplyOpen}
            />

            <CaseSettingsDialog
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

OffboardingCasePage.layout = (props: CasePageProps) => ({
    breadcrumbs: [
        { title: 'Offboarding', href: offboardingRoutes.index },
        {
            title: props.case.employee?.full_name ?? 'Case',
            href: offboardingRoutes.show(props.case.hashid),
        },
    ],
});
