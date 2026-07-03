import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    CalendarRange,
    Pencil,
    Plus,
    Target,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { CriterionFormSheet } from '@/features/kpi-config/components/criterion-form-sheet';
import { PeriodFormSheet } from '@/features/kpi-config/components/period-form-sheet';
import { kpiConfigRoutes } from '@/features/kpi-config/routes';
import type {
    KpiCriterion,
    KpiSetupPageProps,
} from '@/features/kpi-config/types';
import { PeriodStatusBadge } from '@/features/performance/components/status-badge';
import { formatDate, scaleDescriptor } from '@/features/performance/constants';
import type { EvaluationPeriodOption } from '@/features/performance/types';
import { cn } from '@/lib/utils';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

export default function SetupKpi() {
    const { criteria, archivedCriteria, periods, archivedPeriods, can } =
        usePage<KpiSetupPageProps>().props;

    const [criterionForm, setCriterionForm] = useState<{
        open: boolean;
        criterion: KpiCriterion | null;
    }>({ open: false, criterion: null });
    const [periodForm, setPeriodForm] = useState<{
        open: boolean;
        period: EvaluationPeriodOption | null;
    }>({ open: false, period: null });

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

    const totalWeight = criteria
        .filter((c) => c.is_active)
        .reduce((sum, c) => sum + c.weight, 0);

    return (
        <>
            <Head title="KPI & Evaluation Criteria" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        KPI &amp; Evaluation Criteria
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        The weighted criteria and review periods the Performance
                        Management module evaluates against.
                    </p>
                </div>

                {/* KPI criteria */}
                <ConfigSection
                    icon={Target}
                    title="KPI criteria"
                    subtitle="Weighted dimensions every evaluation scores against."
                    canManage={can.manage}
                    onNew={() =>
                        setCriterionForm({ open: true, criterion: null })
                    }
                    empty={criteria.length === 0}
                    emptyLabel="No KPI criteria yet."
                    archived={archivedCriteria}
                    onRestore={(c) =>
                        router.patch(
                            kpiConfigRoutes.criteria.restore(c.hashid),
                            {},
                            { preserveScroll: true },
                        )
                    }
                    onForceDelete={(c) =>
                        askConfirm({
                            title: `Permanently delete "${c.name}"?`,
                            description:
                                'This cannot be undone. A criterion used by evaluations cannot be permanently deleted.',
                            confirmLabel: 'Delete permanently',
                            run: () =>
                                router.delete(
                                    kpiConfigRoutes.criteria.forceDelete(
                                        c.hashid,
                                    ),
                                    withProcessing,
                                ),
                        })
                    }
                    aside={
                        criteria.length > 0 && (
                            <span
                                className={cn(
                                    'text-xs font-medium tabular-nums',
                                    Math.round(totalWeight) === 100
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-amber-600 dark:text-amber-400',
                                )}
                            >
                                Active weight: {totalWeight}%
                            </span>
                        )
                    }
                >
                    {criteria.map((criterion) => (
                        <div
                            key={criterion.id}
                            className="flex items-center gap-3 px-4 py-3"
                        >
                            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-sm font-semibold text-[#0a8b91] tabular-nums dark:text-[#0ABFBF]">
                                {criterion.weight}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="truncate text-sm font-medium">
                                        {criterion.name}
                                    </p>
                                    <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                                        {scaleDescriptor(criterion)}
                                    </span>
                                    {!criterion.is_active && (
                                        <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                                            Inactive
                                        </span>
                                    )}
                                </div>
                                {criterion.description && (
                                    <p className="mt-0.5 truncate text-xs text-muted-foreground">
                                        {criterion.description}
                                    </p>
                                )}
                            </div>
                            <span className="hidden text-xs text-muted-foreground tabular-nums sm:block">
                                {criterion.usage_count}{' '}
                                {criterion.usage_count === 1
                                    ? 'evaluation'
                                    : 'evaluations'}
                            </span>
                            {can.manage && (
                                <RowActions
                                    onEdit={() =>
                                        setCriterionForm({
                                            open: true,
                                            criterion,
                                        })
                                    }
                                    onArchive={() =>
                                        askConfirm({
                                            title: `Archive "${criterion.name}"?`,
                                            description:
                                                'It is excluded from new evaluations; existing ones keep it. You can restore it later.',
                                            confirmLabel: 'Archive',
                                            run: () =>
                                                router.delete(
                                                    kpiConfigRoutes.criteria.destroy(
                                                        criterion.hashid,
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

                {/* Evaluation periods */}
                <ConfigSection
                    icon={CalendarRange}
                    title="Evaluation periods"
                    subtitle="The review cycles evaluations are conducted within."
                    canManage={can.manage}
                    onNew={() => setPeriodForm({ open: true, period: null })}
                    empty={periods.length === 0}
                    emptyLabel="No evaluation periods yet."
                    archived={archivedPeriods}
                    onRestore={(p) =>
                        router.patch(
                            kpiConfigRoutes.periods.restore(p.hashid),
                            {},
                            { preserveScroll: true },
                        )
                    }
                    onForceDelete={(p) =>
                        askConfirm({
                            title: `Permanently delete "${p.name}"?`,
                            description:
                                'This cannot be undone. A period with evaluations cannot be permanently deleted.',
                            confirmLabel: 'Delete permanently',
                            run: () =>
                                router.delete(
                                    kpiConfigRoutes.periods.forceDelete(
                                        p.hashid,
                                    ),
                                    withProcessing,
                                ),
                        })
                    }
                >
                    {periods.map((period) => (
                        <div
                            key={period.id}
                            className="flex items-center gap-3 px-4 py-3"
                        >
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <p className="truncate text-sm font-medium">
                                        {period.name}
                                    </p>
                                    <PeriodStatusBadge status={period.status} />
                                </div>
                                <p className="mt-0.5 truncate text-xs text-muted-foreground tabular-nums">
                                    {formatDate(period.start_date)} –{' '}
                                    {formatDate(period.end_date)}
                                </p>
                            </div>
                            <span className="hidden text-xs text-muted-foreground tabular-nums sm:block">
                                {period.evaluations_count}{' '}
                                {period.evaluations_count === 1
                                    ? 'evaluation'
                                    : 'evaluations'}
                            </span>
                            {can.manage && (
                                <RowActions
                                    onEdit={() =>
                                        setPeriodForm({ open: true, period })
                                    }
                                    onArchive={() =>
                                        askConfirm({
                                            title: `Archive "${period.name}"?`,
                                            description:
                                                'It is hidden from the picker; existing evaluations are kept. You can restore it later.',
                                            confirmLabel: 'Archive',
                                            run: () =>
                                                router.delete(
                                                    kpiConfigRoutes.periods.destroy(
                                                        period.hashid,
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

            <CriterionFormSheet
                criterion={criterionForm.criterion}
                open={criterionForm.open}
                onOpenChange={(open) =>
                    setCriterionForm((prev) => ({ ...prev, open }))
                }
            />
            <PeriodFormSheet
                period={periodForm.period}
                open={periodForm.open}
                onOpenChange={(open) =>
                    setPeriodForm((prev) => ({ ...prev, open }))
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
    aside,
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
    aside?: ReactNode;
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
                    {aside}
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

SetupKpi.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'KPI & Evaluation Criteria', href: '/setup/kpi' },
    ],
};
