import { Head, router, usePage } from '@inertiajs/react';
import { Landmark } from 'lucide-react';
import { PersonAvatar } from '@/components/person-avatar';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { BenefitsTabs } from '@/features/benefits/components/benefits-tabs';
import { formatPeso, STATUTORY_LABELS } from '@/features/benefits/constants';
import type {
    ContributionsPageProps,
    StatutoryBenefit,
} from '@/features/benefits/types';
import { cn } from '@/lib/utils';

const BENEFITS: StatutoryBenefit[] = ['sss', 'philhealth', 'pagibig'];

const ACCENT: Record<StatutoryBenefit, string> = {
    sss: 'text-sky-600 bg-sky-500/10 dark:text-sky-400',
    philhealth: 'text-emerald-600 bg-emerald-500/10 dark:text-emerald-400',
    pagibig: 'text-amber-600 bg-amber-500/10 dark:text-amber-400',
};

export default function BenefitsContributions() {
    const { periods, selected, summary, rows } =
        usePage<ContributionsPageProps>().props;

    const onPeriod = (value: string) =>
        router.get(
            '/benefits/contributions',
            { period: value },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Statutory Contributions" />

            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        Statutory Contributions
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Monthly SSS, PhilHealth & Pag-IBIG remittance — employee
                        and employer shares from processed payroll.
                    </p>
                </div>

                <BenefitsTabs />

                {periods.length === 0 ? (
                    <EmptyState />
                ) : (
                    <>
                        <div className="flex items-center justify-between gap-3">
                            <p className="text-sm text-muted-foreground">
                                Remittance month
                            </p>
                            <Select
                                value={selected ?? undefined}
                                onValueChange={onPeriod}
                            >
                                <SelectTrigger className="w-48">
                                    <SelectValue placeholder="Select month" />
                                </SelectTrigger>
                                <SelectContent>
                                    {periods.map((p) => (
                                        <SelectItem
                                            key={p.value}
                                            value={p.value}
                                        >
                                            {p.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Per-agency summary */}
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            {BENEFITS.map((benefit) => (
                                <div
                                    key={benefit}
                                    className="rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border"
                                >
                                    <div className="flex items-center justify-between">
                                        <span className="text-sm font-medium">
                                            {STATUTORY_LABELS[benefit]}
                                        </span>
                                        <span
                                            className={cn(
                                                'flex size-7 items-center justify-center rounded-lg',
                                                ACCENT[benefit],
                                            )}
                                        >
                                            <Landmark className="size-4" />
                                        </span>
                                    </div>
                                    <p className="mt-2 text-2xl font-semibold tracking-tight tabular-nums">
                                        {formatPeso(
                                            summary.benefits[benefit].total,
                                        )}
                                    </p>
                                    <p className="mt-1 text-[11px] text-muted-foreground tabular-nums">
                                        EE{' '}
                                        {formatPeso(
                                            summary.benefits[benefit].employee,
                                        )}{' '}
                                        · ER{' '}
                                        {formatPeso(
                                            summary.benefits[benefit].employer,
                                        )}
                                    </p>
                                </div>
                            ))}
                        </div>

                        {/* Remittance register */}
                        <div className="overflow-x-auto rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                            <Table>
                                <TableHeader>
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>Employee</TableHead>
                                        {BENEFITS.map((b) => (
                                            <TableHead
                                                key={b}
                                                className="text-right"
                                            >
                                                {STATUTORY_LABELS[b]}
                                            </TableHead>
                                        ))}
                                        <TableHead className="text-right">
                                            Total
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((row) => (
                                        <TableRow key={row.employee?.id}>
                                            <TableCell className="py-2.5">
                                                <div className="flex items-center gap-3">
                                                    <PersonAvatar
                                                        name={
                                                            row.employee
                                                                ?.full_name ??
                                                            'Unknown'
                                                        }
                                                        initials={
                                                            row.employee
                                                                ?.initials ??
                                                            '?'
                                                        }
                                                        photo={
                                                            row.employee?.photo
                                                        }
                                                        className="size-8"
                                                    />
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-medium">
                                                            {row.employee
                                                                ?.full_name ??
                                                                'Unknown'}
                                                        </p>
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {row.employee
                                                                ?.employee_no ??
                                                                '—'}
                                                        </p>
                                                    </div>
                                                </div>
                                            </TableCell>
                                            {BENEFITS.map((b) => (
                                                <TableCell
                                                    key={b}
                                                    className="text-right align-middle tabular-nums"
                                                >
                                                    <ShareCell
                                                        employee={
                                                            row.shares[b]
                                                                .employee
                                                        }
                                                        employer={
                                                            row.shares[b]
                                                                .employer
                                                        }
                                                    />
                                                </TableCell>
                                            ))}
                                            <TableCell className="text-right text-sm font-semibold tabular-nums">
                                                {formatPeso(row.total)}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                                <TableFooter>
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell className="text-sm font-semibold">
                                            {summary.employees} employees
                                        </TableCell>
                                        {BENEFITS.map((b) => (
                                            <TableCell
                                                key={b}
                                                className="text-right text-sm font-semibold tabular-nums"
                                            >
                                                {formatPeso(
                                                    summary.benefits[b].total,
                                                )}
                                            </TableCell>
                                        ))}
                                        <TableCell className="text-right text-sm font-bold text-[#0a8b91] tabular-nums dark:text-[#0ABFBF]">
                                            {formatPeso(summary.total)}
                                        </TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </div>

                        <p className="text-xs text-muted-foreground">
                            Each cell shows the employee share (top) and
                            employer share (bottom). Totals include both.
                        </p>
                    </>
                )}
            </div>
        </>
    );
}

function ShareCell({
    employee,
    employer,
}: {
    employee: number;
    employer: number;
}) {
    return (
        <div className="flex flex-col items-end leading-tight">
            <span className="text-sm">{formatPeso(employee)}</span>
            <span className="text-[11px] text-muted-foreground">
                {formatPeso(employer)}
            </span>
        </div>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-sidebar-border/70 bg-card/50 px-6 py-16 text-center dark:border-sidebar-border">
            <span className="flex size-11 items-center justify-center rounded-full bg-[#0ABFBF]/10 text-[#0ABFBF]">
                <Landmark className="size-5" />
            </span>
            <p className="text-sm font-medium">No contributions yet</p>
            <p className="max-w-sm text-sm text-muted-foreground">
                Process a payroll run to generate the SSS, PhilHealth and
                Pag-IBIG contributions for its month.
            </p>
        </div>
    );
}

BenefitsContributions.layout = {
    breadcrumbs: [
        { title: 'Benefits', href: '/benefits' },
        { title: 'Contributions', href: '/benefits/contributions' },
    ],
};
