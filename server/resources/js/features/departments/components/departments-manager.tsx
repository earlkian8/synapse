import { router } from '@inertiajs/react';
import { ArchiveRestore, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { departmentRoutes } from '../routes';
import type { Department, DepartmentsPageProps, Position } from '../types';
import { ConfirmDialog } from './confirm-dialog';
import { DepartmentDetailSheet } from './department-detail-sheet';
import { DepartmentFormSheet } from './department-form-sheet';
import { DepartmentStatsCards } from './department-stats';
import { DepartmentToolbar } from './department-toolbar';
import { DepartmentTree } from './department-tree';
import { PositionFormSheet } from './position-form-sheet';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

type Props = DepartmentsPageProps & {
    /** The headline counts above the board — the Departments screen's, not the wizard's. */
    showStats?: boolean;
};

/**
 * The org-structure board with every action Company Setup offers on it: the
 * department hierarchy (nest, edit, archive, restore, delete), each
 * department's head, defaults and positions. Rendered by the Departments screen
 * and by the setup wizard's step for it, from the same props.
 */
export function DepartmentsManager({
    departments,
    archived,
    stats,
    options,
    can,
    showStats = true,
}: Props) {
    const [search, setSearch] = useState('');
    const [showArchived, setShowArchived] = useState(false);

    const [formOpen, setFormOpen] = useState(false);
    const [formDepartment, setFormDepartment] = useState<Department | null>(
        null,
    );
    const [formParent, setFormParent] = useState<Department | null>(null);

    const [detailOpen, setDetailOpen] = useState(false);
    const [detailId, setDetailId] = useState<number | null>(null);

    const [positionOpen, setPositionOpen] = useState(false);
    const [positionEditing, setPositionEditing] = useState<Position | null>(
        null,
    );
    const [positionDept, setPositionDept] = useState<Department | null>(null);

    const [confirm, setConfirm] = useState<ConfirmConfig | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    // Re-derive the open department from fresh props so it reflects mutations.
    const detailDepartment = useMemo(
        () =>
            [...departments, ...archived].find((d) => d.id === detailId) ??
            null,
        [departments, archived, detailId],
    );

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

    const openCreate = (parent: Department | null = null) => {
        setFormDepartment(null);
        setFormParent(parent);
        setFormOpen(true);
    };

    const openEdit = (department: Department) => {
        setFormDepartment(department);
        setFormParent(null);
        setFormOpen(true);
    };

    const openDetail = (department: Department) => {
        setDetailId(department.id);
        setDetailOpen(true);
    };

    const archive = (department: Department) =>
        askConfirm({
            title: `Archive "${department.name}"?`,
            description:
                'The department is hidden from the structure. Its positions and employees keep their records; you can restore it later.',
            confirmLabel: 'Archive',
            run: () => {
                setDetailOpen(false);
                router.delete(
                    departmentRoutes.department(department.hashid),
                    withProcessing,
                );
            },
        });

    const restore = (department: Department) =>
        router.patch(
            departmentRoutes.restore(department.hashid),
            {},
            { preserveScroll: true },
        );

    const forceDelete = (department: Department) =>
        askConfirm({
            title: `Permanently delete "${department.name}"?`,
            description:
                'This cannot be undone. Sub-departments, positions and employees are detached (not deleted).',
            confirmLabel: 'Delete permanently',
            run: () =>
                router.delete(
                    departmentRoutes.forceDelete(department.hashid),
                    withProcessing,
                ),
        });

    const addPosition = (department: Department) => {
        setPositionDept(department);
        setPositionEditing(null);
        setPositionOpen(true);
    };

    const editPosition = (position: Position) => {
        setPositionDept(detailDepartment);
        setPositionEditing(position);
        setPositionOpen(true);
    };

    const deletePosition = (position: Position) =>
        askConfirm({
            title: `Delete "${position.title}"?`,
            description:
                'Employees holding this position keep their record (the link is cleared).',
            confirmLabel: 'Delete position',
            run: () =>
                router.delete(
                    departmentRoutes.position(position.id),
                    withProcessing,
                ),
        });

    return (
        <>
            {showStats && <DepartmentStatsCards stats={stats} />}

            <div className="flex flex-col gap-4">
                <DepartmentToolbar
                    search={search}
                    onSearch={setSearch}
                    showArchived={showArchived}
                    onToggleArchived={() => setShowArchived((v) => !v)}
                    archivedCount={archived.length}
                    canManage={can.manage}
                    onCreate={() => openCreate(null)}
                />

                <DepartmentTree
                    departments={departments}
                    search={search}
                    canManage={can.manage}
                    onOpen={openDetail}
                    onEdit={openEdit}
                    onAddSub={(parent) => openCreate(parent)}
                    onArchive={archive}
                />

                {showArchived && archived.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Archived
                        </h2>
                        {archived.map((department) => (
                            <div
                                key={department.id}
                                className="flex items-center gap-3 rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-3 py-2.5 dark:border-sidebar-border"
                            >
                                <div className="min-w-0 flex-1">
                                    <span className="text-sm font-medium text-muted-foreground">
                                        {department.name}
                                    </span>
                                    <span className="ml-2 rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">
                                        {department.code}
                                    </span>
                                </div>
                                {can.manage && (
                                    <>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => restore(department)}
                                        >
                                            <ArchiveRestore className="size-4" />
                                            Restore
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground hover:text-destructive"
                                            onClick={() =>
                                                forceDelete(department)
                                            }
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

            <DepartmentFormSheet
                department={formDepartment}
                parentDefault={formParent}
                departments={departments}
                employees={options.employees}
                schedules={options.schedules}
                policies={options.policies}
                open={formOpen}
                onOpenChange={setFormOpen}
            />

            <DepartmentDetailSheet
                department={detailDepartment}
                canManage={can.manage}
                open={detailOpen}
                onOpenChange={setDetailOpen}
                onEdit={openEdit}
                onArchive={archive}
                onAddPosition={addPosition}
                onEditPosition={editPosition}
                onDeletePosition={deletePosition}
            />

            {positionDept && (
                <PositionFormSheet
                    position={positionEditing}
                    departmentHashid={positionDept.hashid}
                    departmentName={positionDept.name}
                    open={positionOpen}
                    onOpenChange={setPositionOpen}
                />
            )}

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
