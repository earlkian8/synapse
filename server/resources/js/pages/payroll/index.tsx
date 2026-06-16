import { Head, router, usePage } from '@inertiajs/react';
import { Plus, Wallet } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { finalizeRun, markPaid } from '@/features/payroll/api';
import { ConfirmDialog } from '@/features/payroll/components/confirm-dialog';
import { NewRunDialog } from '@/features/payroll/components/new-run-dialog';
import { PayrollRunCard } from '@/features/payroll/components/payroll-run-card';
import { PayrollStatsCards } from '@/features/payroll/components/payroll-stats';
import { STATUS_FILTERS } from '@/features/payroll/constants';
import { payrollRoutes } from '@/features/payroll/routes';
import type {
    PayrollIndexPageProps,
    PayrollPeriod,
} from '@/features/payroll/types';
import { cn } from '@/lib/utils';

type Action = { kind: 'finalize' | 'paid'; period: PayrollPeriod } | null;

export default function PayrollIndex() {
    const { periods, stats, can, filters } =
        usePage<PayrollIndexPageProps>().props;

    const [newRunOpen, setNewRunOpen] = useState(false);
    const [action, setAction] = useState<Action>(null);
    const [processing, setProcessing] = useState(false);

    const setStatus = (status: string) => {
        router.get(payrollRoutes.index, status === 'all' ? {} : { status }, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['periods', 'filters'],
        });
    };

    const confirm = () => {
        if (!action) {
            return;
        }

        const fn = action.kind === 'finalize' ? finalizeRun : markPaid;
        fn(action.period.hashid, {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setAction(null);
            },
        });
    };

    return (
        <>
            <Head title="Payroll" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Payroll
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Pay runs and payslips — computed from salaries and
                            attendance.
                        </p>
                    </div>
                    {can.process && (
                        <Button size="sm" onClick={() => setNewRunOpen(true)}>
                            <Plus className="size-4" />
                            New run
                        </Button>
                    )}
                </div>

                <PayrollStatsCards stats={stats} />

                {/* Status tabs */}
                <div className="-mb-px flex items-center gap-1 overflow-x-auto border-b border-border">
                    {STATUS_FILTERS.map((tab) => {
                        const active = (filters.status || 'all') === tab.value;

                        return (
                            <button
                                key={tab.value}
                                type="button"
                                onClick={() => setStatus(tab.value)}
                                className={cn(
                                    'relative border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                    active
                                        ? 'border-[#0ABFBF] text-foreground'
                                        : 'border-transparent text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {tab.label}
                            </button>
                        );
                    })}
                </div>

                {periods.length === 0 ? (
                    <EmptyState />
                ) : (
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                        {periods.map((period) => (
                            <PayrollRunCard
                                key={period.id}
                                period={period}
                                can={can}
                                onFinalize={(p) =>
                                    setAction({ kind: 'finalize', period: p })
                                }
                                onMarkPaid={(p) =>
                                    setAction({ kind: 'paid', period: p })
                                }
                            />
                        ))}
                    </div>
                )}
            </div>

            <NewRunDialog open={newRunOpen} onOpenChange={setNewRunOpen} />

            <ConfirmDialog
                open={action !== null}
                onOpenChange={(open) => !open && setAction(null)}
                title={
                    action?.kind === 'finalize'
                        ? 'Finalize this run?'
                        : 'Mark this run as paid?'
                }
                description={
                    action?.kind === 'finalize'
                        ? 'Finalizing locks every payslip in this run from further changes.'
                        : 'This releases every payslip to its employee and marks the run paid.'
                }
                confirmLabel={
                    action?.kind === 'finalize' ? 'Finalize' : 'Mark paid'
                }
                processing={processing}
                onConfirm={confirm}
            />
        </>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Wallet className="size-5" />
            </span>
            <p className="text-sm font-medium">No payroll runs yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Create a run to generate payslips for the period from salaries
                and attendance.
            </p>
        </div>
    );
}

PayrollIndex.layout = {
    breadcrumbs: [{ title: 'Payroll', href: '/payroll' }],
};
