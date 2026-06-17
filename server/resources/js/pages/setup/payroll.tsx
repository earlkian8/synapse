import { Head, router, usePage } from '@inertiajs/react';
import {
    Archive,
    ArchiveRestore,
    Coins,
    Pencil,
    Plus,
    Receipt,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { ConfirmDialog } from '@/features/payroll/components/confirm-dialog';
import { AllowanceFormSheet } from '@/features/payroll-config/components/allowance-form-sheet';
import { DeductionFormSheet } from '@/features/payroll-config/components/deduction-form-sheet';
import { payrollConfigRoutes } from '@/features/payroll-config/routes';
import { DEDUCTION_KIND_LABELS } from '@/features/payroll-config/types';
import type {
    AllowanceType,
    DeductionType,
    PayrollSetupPageProps,
} from '@/features/payroll-config/types';
import { cn } from '@/lib/utils';

type ConfirmConfig = {
    title: string;
    description: ReactNode;
    confirmLabel: string;
    run: () => void;
};

export default function SetupPayroll() {
    const {
        allowanceTypes,
        archivedAllowances,
        deductionTypes,
        archivedDeductions,
        can,
    } = usePage<PayrollSetupPageProps>().props;

    const [allowanceForm, setAllowanceForm] = useState<{
        open: boolean;
        type: AllowanceType | null;
    }>({ open: false, type: null });
    const [deductionForm, setDeductionForm] = useState<{
        open: boolean;
        type: DeductionType | null;
    }>({ open: false, type: null });

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
            <Head title="Payroll Configuration" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Payroll Configuration
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        The allowance and deduction types the payroll engine
                        applies when building payslips.
                    </p>
                </div>

                {/* Allowance types */}
                <Section
                    icon={Coins}
                    title="Allowance types"
                    subtitle="Additional earnings that can appear on a payslip."
                    canManage={can.manage}
                    onNew={() => setAllowanceForm({ open: true, type: null })}
                    empty={allowanceTypes.length === 0}
                    emptyLabel="No allowance types yet."
                    archived={archivedAllowances}
                    renderArchivedName={(t) => t.name}
                    onRestore={(t) =>
                        router.patch(
                            payrollConfigRoutes.allowance.restore(t.hashid),
                            {},
                            { preserveScroll: true },
                        )
                    }
                    onForceDelete={(t) =>
                        askConfirm({
                            title: `Permanently delete "${t.name}"?`,
                            description:
                                'This cannot be undone. Historical payslip lines keep their label.',
                            confirmLabel: 'Delete permanently',
                            run: () =>
                                router.delete(
                                    payrollConfigRoutes.allowance.forceDelete(
                                        t.hashid,
                                    ),
                                    withProcessing,
                                ),
                        })
                    }
                >
                    {allowanceTypes.map((type) => (
                        <Row
                            key={type.id}
                            name={type.name}
                            meta={
                                <Badge>
                                    {type.is_taxable
                                        ? 'Taxable'
                                        : 'Non-taxable'}
                                </Badge>
                            }
                            usage={type.usage_count}
                            canManage={can.manage}
                            onEdit={() =>
                                setAllowanceForm({ open: true, type })
                            }
                            onArchive={() =>
                                askConfirm({
                                    title: `Archive "${type.name}"?`,
                                    description:
                                        'It is hidden from new payslips; existing ones keep it. You can restore it later.',
                                    confirmLabel: 'Archive',
                                    run: () =>
                                        router.delete(
                                            payrollConfigRoutes.allowance.destroy(
                                                type.hashid,
                                            ),
                                            withProcessing,
                                        ),
                                })
                            }
                        />
                    ))}
                </Section>

                {/* Deduction types */}
                <Section
                    icon={Receipt}
                    title="Deduction types"
                    subtitle="Statutory contributions, taxes and other deductions."
                    canManage={can.manage}
                    onNew={() => setDeductionForm({ open: true, type: null })}
                    empty={deductionTypes.length === 0}
                    emptyLabel="No deduction types yet."
                    archived={archivedDeductions}
                    renderArchivedName={(t) => t.name}
                    onRestore={(t) =>
                        router.patch(
                            payrollConfigRoutes.deduction.restore(t.hashid),
                            {},
                            { preserveScroll: true },
                        )
                    }
                    onForceDelete={(t) =>
                        askConfirm({
                            title: `Permanently delete "${t.name}"?`,
                            description:
                                'This cannot be undone. Historical payslip lines keep their label.',
                            confirmLabel: 'Delete permanently',
                            run: () =>
                                router.delete(
                                    payrollConfigRoutes.deduction.forceDelete(
                                        t.hashid,
                                    ),
                                    withProcessing,
                                ),
                        })
                    }
                >
                    {deductionTypes.map((type) => (
                        <Row
                            key={type.id}
                            name={type.name}
                            meta={
                                <>
                                    <Badge>
                                        {DEDUCTION_KIND_LABELS[type.kind]}
                                    </Badge>
                                    {type.is_mandatory && (
                                        <Badge tone="accent">Mandatory</Badge>
                                    )}
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {type.computation_type === 'bracket'
                                            ? 'Progressive bracket'
                                            : type.rate != null
                                              ? `${type.rate}%${type.cap ? ` · cap ₱${type.cap.toLocaleString()}` : ''}`
                                              : '—'}
                                    </span>
                                </>
                            }
                            usage={type.usage_count}
                            canManage={can.manage}
                            onEdit={() =>
                                setDeductionForm({ open: true, type })
                            }
                            onArchive={() =>
                                askConfirm({
                                    title: `Archive "${type.name}"?`,
                                    description:
                                        'It is hidden from new payslips; existing ones keep it. You can restore it later.',
                                    confirmLabel: 'Archive',
                                    run: () =>
                                        router.delete(
                                            payrollConfigRoutes.deduction.destroy(
                                                type.hashid,
                                            ),
                                            withProcessing,
                                        ),
                                })
                            }
                        />
                    ))}
                </Section>
            </div>

            <AllowanceFormSheet
                type={allowanceForm.type}
                open={allowanceForm.open}
                onOpenChange={(open) =>
                    setAllowanceForm((prev) => ({ ...prev, open }))
                }
            />
            <DeductionFormSheet
                type={deductionForm.type}
                open={deductionForm.open}
                onOpenChange={(open) =>
                    setDeductionForm((prev) => ({ ...prev, open }))
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

function Section<T extends ArchivedItem>({
    icon: Icon,
    title,
    subtitle,
    canManage,
    onNew,
    empty,
    emptyLabel,
    archived,
    renderArchivedName,
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
    renderArchivedName: (item: T) => string;
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
                                {renderArchivedName(item)}
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

function Row({
    name,
    meta,
    usage,
    canManage,
    onEdit,
    onArchive,
}: {
    name: string;
    meta: ReactNode;
    usage: number;
    canManage: boolean;
    onEdit: () => void;
    onArchive: () => void;
}) {
    return (
        <div className="flex items-center gap-3 px-4 py-3">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{name}</p>
                <div className="mt-1 flex flex-wrap items-center gap-1.5">
                    {meta}
                </div>
            </div>
            <span className="hidden text-xs text-muted-foreground tabular-nums sm:block">
                {usage} {usage === 1 ? 'payslip' : 'payslips'}
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

function Badge({
    children,
    tone = 'muted',
}: {
    children: ReactNode;
    tone?: 'muted' | 'accent';
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                tone === 'accent'
                    ? 'border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-[#0a8b91] dark:text-[#0ABFBF]'
                    : 'border-border bg-muted text-muted-foreground',
            )}
        >
            {children}
        </span>
    );
}

SetupPayroll.layout = {
    breadcrumbs: [
        { title: 'Company Setup', href: '/setup/departments' },
        { title: 'Payroll Configuration', href: '/setup/payroll' },
    ],
};
