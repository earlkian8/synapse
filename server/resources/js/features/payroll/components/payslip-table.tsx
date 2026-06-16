import { ChevronDown, FileText } from 'lucide-react';
import { Fragment, useState } from 'react';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { formatPeso } from '../constants';
import type { Payslip } from '../types';
import { PayslipStatusBadge } from './payroll-status-badge';

/**
 * The per-employee payslip table for a run, with a collapsible itemized
 * breakdown per row and a "View details" action that opens the payslip document.
 */
export function PayslipTable({
    payslips,
    onView,
}: {
    payslips: Payslip[];
    onView: (payslip: Payslip) => void;
}) {
    const [expanded, setExpanded] = useState<Set<number>>(new Set());

    const toggle = (id: number) =>
        setExpanded((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
            <Table>
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        <TableHead className="w-8" />
                        <TableHead>Employee</TableHead>
                        <TableHead className="text-right">Days</TableHead>
                        <TableHead className="hidden text-right sm:table-cell">
                            Gross
                        </TableHead>
                        <TableHead className="hidden text-right md:table-cell">
                            Deductions
                        </TableHead>
                        <TableHead className="text-right">Net pay</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="text-right">Details</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {payslips.map((payslip) => {
                        const isOpen = expanded.has(payslip.id);

                        return (
                            <Fragment key={payslip.id}>
                                <TableRow
                                    className="cursor-pointer"
                                    onClick={() => toggle(payslip.id)}
                                >
                                    <TableCell className="text-muted-foreground">
                                        <ChevronDown
                                            className={cn(
                                                'size-4 transition-transform',
                                                isOpen && 'rotate-180',
                                            )}
                                        />
                                    </TableCell>
                                    <TableCell className="py-2.5">
                                        <div className="flex items-center gap-3">
                                            <PersonAvatar
                                                name={
                                                    payslip.employee
                                                        ?.full_name ?? 'Unknown'
                                                }
                                                initials={
                                                    payslip.employee
                                                        ?.initials ?? '?'
                                                }
                                                photo={payslip.employee?.photo}
                                                className="size-9"
                                            />
                                            <div className="min-w-0">
                                                <p className="truncate text-sm font-medium">
                                                    {payslip.employee
                                                        ?.full_name ??
                                                        'Unknown employee'}
                                                </p>
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {payslip.employee
                                                        ?.position ??
                                                        payslip.employee
                                                            ?.employee_no ??
                                                        '—'}
                                                </p>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right text-sm tabular-nums">
                                        {payslip.days_worked}
                                    </TableCell>
                                    <TableCell className="hidden text-right text-sm tabular-nums sm:table-cell">
                                        {formatPeso(payslip.gross_pay)}
                                    </TableCell>
                                    <TableCell className="hidden text-right text-sm text-rose-600 tabular-nums md:table-cell dark:text-rose-400">
                                        −{formatPeso(payslip.total_deductions)}
                                    </TableCell>
                                    <TableCell className="text-right text-sm font-semibold tabular-nums">
                                        {formatPeso(payslip.net_pay)}
                                    </TableCell>
                                    <TableCell>
                                        <PayslipStatusBadge
                                            status={payslip.status}
                                        />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onClick={(e) => {
                                                e.stopPropagation();
                                                onView(payslip);
                                            }}
                                        >
                                            <FileText className="size-4" />
                                            View
                                        </Button>
                                    </TableCell>
                                </TableRow>

                                {isOpen && (
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell />
                                        <TableCell colSpan={7} className="py-3">
                                            <Breakdown payslip={payslip} />
                                        </TableCell>
                                    </TableRow>
                                )}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

function Breakdown({ payslip }: { payslip: Payslip }) {
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

    return (
        <div className="grid gap-6 rounded-lg bg-muted/40 p-4 sm:grid-cols-2">
            <LineGroup
                title="Earnings"
                lines={earnings}
                total={payslip.gross_pay}
                totalLabel="Gross pay"
            />
            <LineGroup
                title="Deductions"
                lines={payslip.deductions ?? []}
                total={payslip.total_deductions}
                totalLabel="Total deductions"
                negative
            />
        </div>
    );
}

function LineGroup({
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
        <div className="flex flex-col gap-1.5">
            <p className="text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </p>
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
            <div className="mt-1 flex items-center justify-between border-t border-border pt-1.5 text-sm font-semibold">
                <span>{totalLabel}</span>
                <span className="tabular-nums">
                    {negative && '−'}
                    {formatPeso(total)}
                </span>
            </div>
        </div>
    );
}
