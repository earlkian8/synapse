import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    CalendarRange,
    Plus,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/features/leave-types/components/confirm-dialog';
import { LeaveTypeCard } from '@/features/leave-types/components/leave-type-card';
import { LeaveTypeFormSheet } from '@/features/leave-types/components/leave-type-form-sheet';
import { leaveTypeRoutes } from '@/features/leave-types/routes';
import type {
    LeaveType,
    LeaveTypesPageProps,
} from '@/features/leave-types/types';
import { cn } from '@/lib/utils';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

export default function SetupLeaveTypes() {
    const { types, archived, can } = usePage<LeaveTypesPageProps>().props;

    const [showArchived, setShowArchived] = useState(false);

    const [formOpen, setFormOpen] = useState(false);
    const [formType, setFormType] = useState<LeaveType | null>(null);

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

    const openCreate = () => {
        setFormType(null);
        setFormOpen(true);
    };

    const openEdit = (type: LeaveType) => {
        setFormType(type);
        setFormOpen(true);
    };

    const archive = (type: LeaveType) =>
        askConfirm({
            title: `Archive "${type.name}"?`,
            description:
                'The type is hidden from new requests. Existing leave keeps it; you can restore it later.',
            confirmLabel: 'Archive',
            run: () =>
                router.delete(
                    leaveTypeRoutes.type(type.hashid),
                    withProcessing,
                ),
        });

    const restore = (type: LeaveType) =>
        router.patch(
            leaveTypeRoutes.restore(type.hashid),
            {},
            { preserveScroll: true },
        );

    const forceDelete = (type: LeaveType) =>
        askConfirm({
            title: `Permanently delete "${type.name}"?`,
            description:
                'This cannot be undone. Only types with no leave requests can be deleted.',
            confirmLabel: 'Delete permanently',
            run: () =>
                router.delete(
                    leaveTypeRoutes.forceDelete(type.hashid),
                    withProcessing,
                ),
        });

    return (
        <>
            <Head title="Leave Types" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Leave Types
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            The kinds of leave the organisation grants, with
                            their entitlement and policy.
                        </p>
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
                        {can.manage && (
                            <Button size="sm" onClick={openCreate}>
                                <Plus className="size-4" />
                                New leave type
                            </Button>
                        )}
                    </div>
                </div>

                {types.length === 0 ? (
                    <EmptyState />
                ) : (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        {types.map((type) => (
                            <LeaveTypeCard
                                key={type.id}
                                type={type}
                                canManage={can.manage}
                                onEdit={openEdit}
                                onArchive={archive}
                            />
                        ))}
                    </div>
                )}

                {showArchived && archived.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Archived
                        </h2>
                        {archived.map((type) => (
                            <div
                                key={type.id}
                                className="flex items-center gap-3 rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-3 py-2.5 dark:border-sidebar-border"
                            >
                                <span
                                    className="size-2.5 shrink-0 rounded-full"
                                    style={{ backgroundColor: type.color }}
                                />
                                <div className="min-w-0 flex-1">
                                    <span className="text-sm font-medium text-muted-foreground">
                                        {type.name}
                                    </span>
                                    <span className="ml-2 rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">
                                        {type.code}
                                    </span>
                                </div>
                                {can.manage && (
                                    <>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => restore(type)}
                                        >
                                            <ArchiveRestore className="size-4" />
                                            Restore
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground hover:text-destructive"
                                            onClick={() => forceDelete(type)}
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
            </div>

            <LeaveTypeFormSheet
                type={formType}
                open={formOpen}
                onOpenChange={setFormOpen}
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

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <CalendarRange className="size-5" />
            </span>
            <p className="text-sm font-medium">No leave types yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Create your first leave type so employees can file time off.
            </p>
        </div>
    );
}

SetupLeaveTypes.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Leave Types', href: '/setup/leave-types' },
    ],
};
