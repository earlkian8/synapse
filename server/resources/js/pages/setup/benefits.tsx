import { Head, router, usePage } from '@inertiajs/react';
import { Archive, ArchiveRestore, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    CATEGORY_LABELS,
    CATEGORY_META,
    FREQUENCY_SUFFIX,
    formatPeso,
} from '@/features/benefits/constants';
import type { BenefitPlan } from '@/features/benefits/types';
import { BenefitPlanFormSheet } from '@/features/benefits-config/components/benefit-plan-form-sheet';
import { benefitsConfigRoutes } from '@/features/benefits-config/routes';
import type { BenefitSetupPageProps } from '@/features/benefits-config/types';
import { ConfirmDialog } from '@/features/payroll/components/confirm-dialog';
import { cn } from '@/lib/utils';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

export default function SetupBenefits() {
    const { plans, archived, can } = usePage<BenefitSetupPageProps>().props;

    const [form, setForm] = useState<{
        open: boolean;
        plan: BenefitPlan | null;
    }>({ open: false, plan: null });
    const [showArchived, setShowArchived] = useState(false);
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
            <Head title="Benefits Configuration" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Benefits Configuration
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            The benefit plans the organisation offers. Employees
                            are enrolled from the Benefits module.
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
                            <Button
                                size="sm"
                                onClick={() =>
                                    setForm({ open: true, plan: null })
                                }
                            >
                                <Plus className="size-4" />
                                New plan
                            </Button>
                        )}
                    </div>
                </div>

                {plans.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-4 py-10 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                        No benefit plans yet.
                    </p>
                ) : (
                    <div className="divide-y divide-border overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
                        {plans.map((plan) => (
                            <PlanRow
                                key={plan.id}
                                plan={plan}
                                canManage={can.manage}
                                onEdit={() => setForm({ open: true, plan })}
                                onArchive={() =>
                                    askConfirm({
                                        title: `Archive "${plan.name}"?`,
                                        description:
                                            'It is hidden from new enrollments; existing ones are kept. You can restore it later.',
                                        confirmLabel: 'Archive',
                                        run: () =>
                                            router.delete(
                                                benefitsConfigRoutes.destroy(
                                                    plan.hashid,
                                                ),
                                                withProcessing,
                                            ),
                                    })
                                }
                            />
                        ))}
                    </div>
                )}

                {showArchived && archived.length > 0 && (
                    <div className="space-y-2">
                        <h2 className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                            Archived plans
                        </h2>
                        {archived.map((plan) => (
                            <div
                                key={plan.id}
                                className="flex items-center gap-3 rounded-lg border border-dashed border-sidebar-border/70 bg-card/50 px-3 py-2.5 dark:border-sidebar-border"
                            >
                                <span className="min-w-0 flex-1 truncate text-sm font-medium text-muted-foreground">
                                    {plan.name}
                                </span>
                                {can.manage && (
                                    <>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={() =>
                                                router.patch(
                                                    benefitsConfigRoutes.restore(
                                                        plan.hashid,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <ArchiveRestore className="size-4" />
                                            Restore
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-8 text-muted-foreground hover:text-destructive"
                                            onClick={() =>
                                                askConfirm({
                                                    title: `Permanently delete "${plan.name}"?`,
                                                    description:
                                                        'This cannot be undone. A plan with enrollments cannot be permanently deleted.',
                                                    confirmLabel:
                                                        'Delete permanently',
                                                    run: () =>
                                                        router.delete(
                                                            benefitsConfigRoutes.forceDelete(
                                                                plan.hashid,
                                                            ),
                                                            withProcessing,
                                                        ),
                                                })
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

            <BenefitPlanFormSheet
                plan={form.plan}
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
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

function PlanRow({
    plan,
    canManage,
    onEdit,
    onArchive,
}: {
    plan: BenefitPlan;
    canManage: boolean;
    onEdit: () => void;
    onArchive: () => void;
}) {
    const meta = CATEGORY_META[plan.category];
    const Icon = meta.icon;

    return (
        <div className="flex items-center gap-3 px-4 py-3">
            <span
                className={cn(
                    'flex size-9 shrink-0 items-center justify-center rounded-lg',
                    meta.accent,
                )}
            >
                <Icon className="size-4.5" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="truncate text-sm font-medium">{plan.name}</p>
                    <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                        {CATEGORY_LABELS[plan.category]}
                    </span>
                    {!plan.is_active && (
                        <span className="rounded-full border border-border bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground">
                            Inactive
                        </span>
                    )}
                </div>
                <p className="mt-0.5 truncate text-xs text-muted-foreground tabular-nums">
                    {plan.provider ?? 'In-house'} · EE{' '}
                    {formatPeso(plan.employee_cost)} · ER{' '}
                    {formatPeso(plan.employer_cost)}
                    {FREQUENCY_SUFFIX[plan.frequency]}
                </p>
            </div>
            <span className="hidden text-xs text-muted-foreground tabular-nums sm:block">
                {plan.enrollments_count}{' '}
                {plan.enrollments_count === 1 ? 'enrollee' : 'enrollees'}
            </span>
            {canManage && (
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
            )}
        </div>
    );
}

SetupBenefits.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Benefits Configuration', href: '/setup/benefits' },
    ],
};
