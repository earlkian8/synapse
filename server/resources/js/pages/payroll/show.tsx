import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, RefreshCw, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    deleteRun,
    finalizeRun,
    markPaid,
    processRun,
} from '@/features/payroll/api';
import { ConfirmDialog } from '@/features/payroll/components/confirm-dialog';
import { PeriodStatusBadge } from '@/features/payroll/components/payroll-status-badge';
import { PayslipDocumentModal } from '@/features/payroll/components/payslip-document-modal';
import { PayslipTable } from '@/features/payroll/components/payslip-table';
import {
    formatDate,
    formatPeso,
    formatRange,
} from '@/features/payroll/constants';
import { payrollRoutes } from '@/features/payroll/routes';
import type { Payslip, PayrollShowPageProps } from '@/features/payroll/types';

type ActionKind = 'process' | 'finalize' | 'paid' | 'delete';

const ACTION_COPY: Record<
    ActionKind,
    {
        title: string;
        description: string;
        confirmLabel: string;
        destructive?: boolean;
    }
> = {
    process: {
        title: 'Re-process this run?',
        description:
            'Payslips are regenerated from the latest salaries and attendance, replacing the current figures.',
        confirmLabel: 'Re-process',
    },
    finalize: {
        title: 'Finalize this run?',
        description:
            'Finalizing locks every payslip in this run from further changes.',
        confirmLabel: 'Finalize',
    },
    paid: {
        title: 'Mark this run as paid?',
        description:
            'This releases every payslip to its employee and marks the run paid.',
        confirmLabel: 'Mark paid',
    },
    delete: {
        title: 'Delete this run?',
        description: 'The run and all its payslips are permanently removed.',
        confirmLabel: 'Delete',
        destructive: true,
    },
};

export default function PayrollShow() {
    const { period, can, catalogue } = usePage<PayrollShowPageProps>().props;
    const payslips = useMemo(() => period.payslips ?? [], [period.payslips]);

    const [detailId, setDetailId] = useState<number | null>(null);
    const [detailOpen, setDetailOpen] = useState(false);
    // Derive the open payslip from the (possibly refreshed) list so it stays live
    // after a manual adjustment without re-opening the modal.
    const detail = useMemo<Payslip | null>(
        () => payslips.find((p) => p.id === detailId) ?? null,
        [payslips, detailId],
    );
    const [action, setAction] = useState<ActionKind | null>(null);
    const [processing, setProcessing] = useState(false);

    const totals = useMemo(
        () =>
            payslips.reduce(
                (acc, p) => ({
                    gross: acc.gross + p.gross_pay,
                    deductions: acc.deductions + p.total_deductions,
                    net: acc.net + p.net_pay,
                }),
                { gross: 0, deductions: 0, net: 0 },
            ),
        [payslips],
    );

    const locked = period.status === 'finalized' || period.status === 'paid';
    const openView = (payslip: Payslip) => {
        setDetailId(payslip.id);
        setDetailOpen(true);
    };

    const run = () => {
        if (!action) {
            return;
        }

        const fns: Record<ActionKind, (h: object) => void> = {
            process: (h) => processRun(period.hashid, h),
            finalize: (h) => finalizeRun(period.hashid, h),
            paid: (h) => markPaid(period.hashid, h),
            delete: (h) => deleteRun(period.hashid, h),
        };

        fns[action]({
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setAction(null);
            },
        });
    };

    return (
        <>
            <Head title={`Payroll — ${period.name}`} />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <Link
                    href={payrollRoutes.index}
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    All runs
                </Link>

                {/* Run header */}
                <div className="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-5 shadow-sm dark:border-sidebar-border">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div className="flex flex-col gap-1">
                            <div className="flex items-center gap-2">
                                <h1 className="text-xl font-semibold tracking-tight">
                                    {period.name}
                                </h1>
                                <PeriodStatusBadge status={period.status} />
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {formatRange(
                                    period.start_date,
                                    period.end_date,
                                )}{' '}
                                · Pay date {formatDate(period.pay_date)}
                            </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                            {can.process && period.status === 'processing' && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setAction('process')}
                                >
                                    <RefreshCw className="size-4" />
                                    Re-process
                                </Button>
                            )}
                            {can.release && period.status === 'processing' && (
                                <Button
                                    size="sm"
                                    onClick={() => setAction('finalize')}
                                >
                                    <CheckCircle2 className="size-4" />
                                    Finalize
                                </Button>
                            )}
                            {can.release && period.status === 'finalized' && (
                                <Button
                                    size="sm"
                                    onClick={() => setAction('paid')}
                                >
                                    <CheckCircle2 className="size-4" />
                                    Mark paid
                                </Button>
                            )}
                            {can.process && !locked && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="text-rose-600 hover:text-rose-700 dark:text-rose-400"
                                    onClick={() => setAction('delete')}
                                >
                                    <Trash2 className="size-4" />
                                    Delete
                                </Button>
                            )}
                        </div>
                    </div>

                    {/* Totals strip */}
                    <div className="grid grid-cols-2 gap-3 border-t border-sidebar-border/60 pt-4 sm:grid-cols-4 dark:border-sidebar-border">
                        <Summary
                            label="Employees"
                            value={payslips.length.toLocaleString()}
                        />
                        <Summary
                            label="Gross"
                            value={formatPeso(totals.gross)}
                        />
                        <Summary
                            label="Deductions"
                            value={formatPeso(totals.deductions)}
                        />
                        <Summary
                            label="Net pay"
                            value={formatPeso(totals.net)}
                            highlight
                        />
                    </div>
                </div>

                {payslips.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-12 text-center text-sm text-muted-foreground dark:border-sidebar-border">
                        This run has no payslips.
                    </p>
                ) : (
                    <PayslipTable payslips={payslips} onView={openView} />
                )}
            </div>

            <PayslipDocumentModal
                payslip={detail}
                open={detailOpen}
                canAdjust={can.adjust}
                catalogue={catalogue}
                onOpenChange={setDetailOpen}
                onChanged={() => router.reload({ only: ['period'] })}
            />

            <ConfirmDialog
                open={action !== null}
                onOpenChange={(open) => !open && setAction(null)}
                title={action ? ACTION_COPY[action].title : ''}
                description={action ? ACTION_COPY[action].description : ''}
                confirmLabel={action ? ACTION_COPY[action].confirmLabel : ''}
                destructive={action ? ACTION_COPY[action].destructive : false}
                processing={processing}
                onConfirm={run}
            />
        </>
    );
}

function Summary({
    label,
    value,
    highlight = false,
}: {
    label: string;
    value: string;
    highlight?: boolean;
}) {
    return (
        <div className="flex flex-col">
            <span className="text-[11px] tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span
                className={
                    highlight
                        ? 'text-lg font-semibold tracking-tight text-[#0a8b91] tabular-nums dark:text-[#0ABFBF]'
                        : 'text-lg font-semibold tracking-tight tabular-nums'
                }
            >
                {value}
            </span>
        </div>
    );
}

PayrollShow.layout = {
    breadcrumbs: [{ title: 'Payroll', href: '/payroll' }],
};
