import { usePage } from '@inertiajs/react';
import { Building2 } from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Auth } from '@/types/auth';
import { formatDate, formatPeso, formatRange } from '../constants';
import type { Payslip } from '../types';
import { PayslipStatusBadge } from './payroll-status-badge';

/**
 * The payslip as a document — a clean letterhead-style layout with itemized
 * earnings and deductions and a net-pay footer, rather than an expanded row.
 */
export function PayslipDocumentModal({
    payslip,
    open,
    onOpenChange,
}: {
    payslip: Payslip | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const company = auth.organization?.name ?? 'Company';

    if (!payslip) {
        return null;
    }

    const earnings = [
        { label: 'Basic Pay', amount: payslip.basic_pay },
        ...(payslip.overtime_pay > 0
            ? [{ label: 'Overtime Pay', amount: payslip.overtime_pay }]
            : []),
        ...(payslip.earnings ?? []).map((e) => ({
            label: e.label,
            amount: e.amount,
        })),
    ];
    const deductions = payslip.deductions ?? [];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] gap-0 overflow-y-auto p-0 sm:max-w-2xl">
                <DialogHeader className="sr-only">
                    <DialogTitle>
                        Payslip — {payslip.employee?.full_name}
                    </DialogTitle>
                </DialogHeader>

                {/* Letterhead */}
                <div className="flex items-start justify-between gap-4 border-b border-border bg-muted/30 px-6 py-5">
                    <div className="flex items-center gap-3">
                        <span className="flex size-10 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                            <Building2 className="size-5" />
                        </span>
                        <div>
                            <p className="font-semibold tracking-tight">
                                {company}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Payslip
                            </p>
                        </div>
                    </div>
                    <div className="text-right">
                        <p className="text-xs text-muted-foreground">
                            {payslip.period?.name}
                        </p>
                        {payslip.period && (
                            <p className="text-xs text-muted-foreground">
                                {formatRange(
                                    payslip.period.start_date,
                                    payslip.period.end_date,
                                )}
                            </p>
                        )}
                        <PayslipStatusBadge
                            status={payslip.status}
                            className="mt-1"
                        />
                    </div>
                </div>

                {/* Employee + pay meta */}
                <div className="grid grid-cols-2 gap-4 px-6 py-5 sm:grid-cols-4">
                    <Meta
                        label="Employee"
                        value={payslip.employee?.full_name ?? '—'}
                    />
                    <Meta
                        label="Employee no."
                        value={payslip.employee?.employee_no ?? '—'}
                    />
                    <Meta
                        label="Department"
                        value={payslip.employee?.department ?? '—'}
                    />
                    <Meta
                        label="Pay date"
                        value={formatDate(payslip.period?.pay_date ?? null)}
                    />
                </div>

                {/* Earnings + deductions */}
                <div className="grid gap-6 px-6 pb-2 sm:grid-cols-2">
                    <Section
                        title="Earnings"
                        lines={earnings}
                        total={payslip.gross_pay}
                        totalLabel="Gross pay"
                    />
                    <Section
                        title="Deductions"
                        lines={deductions}
                        total={payslip.total_deductions}
                        totalLabel="Total deductions"
                        negative
                    />
                </div>

                {/* Net pay footer */}
                <div className="mt-3 flex items-center justify-between border-t border-border bg-[#0ABFBF]/5 px-6 py-5">
                    <div>
                        <p className="text-xs tracking-wide text-muted-foreground uppercase">
                            Net pay
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {payslip.days_worked} days worked
                        </p>
                    </div>
                    <p className="text-2xl font-bold tracking-tight text-[#0a8b91] tabular-nums dark:text-[#0ABFBF]">
                        {formatPeso(payslip.net_pay)}
                    </p>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function Meta({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex flex-col">
            <span className="text-[11px] tracking-wide text-muted-foreground uppercase">
                {label}
            </span>
            <span className="truncate text-sm font-medium">{value}</span>
        </div>
    );
}

function Section({
    title,
    lines,
    total,
    totalLabel,
    negative = false,
}: {
    title: string;
    lines: { label: string; amount: number }[];
    total: number;
    totalLabel: string;
    negative?: boolean;
}) {
    return (
        <div className="flex flex-col gap-2">
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </p>
            <div className="flex flex-col gap-1.5">
                {lines.length === 0 ? (
                    <p className="text-sm text-muted-foreground">None</p>
                ) : (
                    lines.map((line) => (
                        <div
                            key={line.label}
                            className="flex items-center justify-between text-sm"
                        >
                            <span className="text-muted-foreground">
                                {line.label}
                            </span>
                            <span className="tabular-nums">
                                {negative && '−'}
                                {formatPeso(line.amount)}
                            </span>
                        </div>
                    ))
                )}
            </div>
            <div className="mt-auto flex items-center justify-between border-t border-border pt-2 text-sm font-semibold">
                <span>{totalLabel}</span>
                <span className="tabular-nums">
                    {negative && '−'}
                    {formatPeso(total)}
                </span>
            </div>
        </div>
    );
}
