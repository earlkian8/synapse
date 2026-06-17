import { Link } from '@inertiajs/react';
import { ArrowRight, CalendarDays, CheckCircle2, Wallet } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { formatPeso, formatRange } from '../constants';
import { payrollRoutes } from '../routes';
import type { PayrollPeriod, PayrollPermissions } from '../types';
import { PeriodStatusBadge } from './payroll-status-badge';

/**
 * A pay run as a hero card — the primary entry point into payroll. Shows the
 * period, its status, the net/gross/headcount totals, and the next lifecycle
 * action; the body links through to the run's payslips.
 */
export function PayrollRunCard({
    period,
    can,
    onFinalize,
    onMarkPaid,
}: {
    period: PayrollPeriod;
    can: PayrollPermissions;
    onFinalize: (period: PayrollPeriod) => void;
    onMarkPaid: (period: PayrollPeriod) => void;
}) {
    const showFinalize = can.release && period.status === 'processing';
    const showMarkPaid = can.release && period.status === 'finalized';

    return (
        <div className="group flex flex-col rounded-xl border border-sidebar-border/70 bg-card shadow-sm transition-all hover:border-[#0ABFBF]/40 hover:shadow-md dark:border-sidebar-border">
            <Link
                href={payrollRoutes.show(period.hashid)}
                className="flex flex-col gap-4 p-5"
            >
                <div className="flex items-start justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-[#0ABFBF]/10 text-[#0ABFBF]">
                            <Wallet className="size-5" />
                        </span>
                        <div className="min-w-0">
                            <p className="truncate font-semibold">
                                {period.name}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {formatRange(
                                    period.start_date,
                                    period.end_date,
                                )}
                            </p>
                        </div>
                    </div>
                    <PeriodStatusBadge status={period.status} />
                </div>

                {/* Net pay — the headline */}
                <div className="rounded-lg bg-muted/50 px-4 py-3">
                    <p className="text-[11px] tracking-wide text-muted-foreground uppercase">
                        Net pay
                    </p>
                    <p className="text-2xl font-semibold tracking-tight tabular-nums">
                        {formatPeso(period.total_net)}
                    </p>
                </div>

                {/* Secondary totals */}
                <div className="grid grid-cols-3 gap-2 text-sm">
                    <Stat
                        label="Gross"
                        value={formatPeso(period.total_gross)}
                    />
                    <Stat
                        label="Deductions"
                        value={formatPeso(period.total_deductions)}
                    />
                    <Stat
                        label="Employees"
                        value={period.headcount.toLocaleString()}
                    />
                </div>

                <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <CalendarDays className="size-3.5" />
                    Pay date{' '}
                    {new Date(`${period.pay_date}T00:00:00`).toLocaleDateString(
                        undefined,
                        {
                            month: 'short',
                            day: 'numeric',
                            year: 'numeric',
                        },
                    )}
                </div>
            </Link>

            <div className="flex items-center gap-2 border-t border-sidebar-border/60 px-5 py-3 dark:border-sidebar-border">
                <Button variant="outline" size="sm" asChild className="flex-1">
                    <Link href={payrollRoutes.show(period.hashid)}>
                        View payslips
                        <ArrowRight className="size-4" />
                    </Link>
                </Button>
                {showFinalize && (
                    <Button
                        size="sm"
                        className="flex-1"
                        onClick={() => onFinalize(period)}
                    >
                        <CheckCircle2 className="size-4" />
                        Finalize
                    </Button>
                )}
                {showMarkPaid && (
                    <Button
                        size="sm"
                        className="flex-1"
                        onClick={() => onMarkPaid(period)}
                    >
                        <CheckCircle2 className="size-4" />
                        Mark paid
                    </Button>
                )}
            </div>
        </div>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div className={cn('flex flex-col')}>
            <span className="text-[11px] text-muted-foreground">{label}</span>
            <span className="truncate font-medium tabular-nums">{value}</span>
        </div>
    );
}
