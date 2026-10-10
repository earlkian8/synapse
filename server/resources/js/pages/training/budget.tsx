import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Landmark,
    PiggyBank,
    ReceiptText,
    Wallet,
    WalletCards,
} from 'lucide-react';
import { useState } from 'react';
import {
    DataTable,
    EmptyTableRow,
    FilterSelect,
    PageBody,
    PageHeader,
    rowOpens,
    StatTiles,
    TableCard,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { FormField } from '@/components/form-field';
import {
    Modal,
    ModalBody,
    ModalContent,
    ModalFooter,
    ModalHeader,
    ModalIcon,
} from '@/components/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { BarList } from '@/features/dashboard/components/charts';
import { setBudget } from '@/features/training/api';
import { BudgetMeter } from '@/features/training/components/budget-meter';
import { MonthColumns } from '@/features/training/components/month-columns';
import { TrainingNav } from '@/features/training/components/training-nav';
import { ProgramStatusBadge } from '@/features/training/components/training-status-badge';
import {
    COST_CATEGORY_LABELS,
    formatDate,
    formatMoney,
    TEXTAREA,
} from '@/features/training/constants';
import { trainingRoutes } from '@/features/training/routes';
import type { BudgetPageProps, CostCategory } from '@/features/training/types';
import { cn } from '@/lib/utils';

/**
 * Training money for a year (ADR 0075): the budget set for it against what was
 * spent, where the money went and when, each program's budget against its
 * spend, and the costs behind every figure. Nothing spent is typed in here —
 * it adds up the costs recorded on each program.
 */
export default function TrainingBudget() {
    const { summary, years, costs, can, nav } =
        usePage<BudgetPageProps>().props;
    const [editing, setEditing] = useState(false);

    const categories = Object.entries(summary.by_category) as [
        CostCategory,
        number,
    ][];
    const now = new Date();
    const page = useClientPagination(costs, String(summary.year));

    return (
        <>
            <Head title={`Training budget ${summary.year}`} />

            <PageBody>
                <PageHeader
                    title="Budget"
                    description="What training may spend in a year, against what it has spent."
                    actions={<TrainingNav current="budget" counts={nav} />}
                />

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <FilterSelect
                        label="Year"
                        value={String(summary.year)}
                        onChange={(value) =>
                            router.get(
                                trainingRoutes.budget(Number(value)),
                                {},
                                { preserveScroll: true },
                            )
                        }
                        options={years.map((y) => ({
                            value: String(y),
                            label: String(y),
                        }))}
                        className="w-32"
                    />
                    {can.manage && (
                        <Button size="sm" onClick={() => setEditing(true)}>
                            <Wallet className="size-4" />
                            {summary.budget === null
                                ? `Set the ${summary.year} budget`
                                : 'Change budget'}
                        </Button>
                    )}
                </div>

                <StatTiles
                    tiles={[
                        {
                            key: 'budget',
                            label: 'Budget',
                            value:
                                summary.budget === null
                                    ? 'Not set'
                                    : formatMoney(summary.budget),
                            icon: Landmark,
                            accent: 'teal',
                        },
                        {
                            key: 'spent',
                            label: 'Spent',
                            value: formatMoney(summary.spent),
                            hint:
                                summary.used_percent === null
                                    ? undefined
                                    : `${summary.used_percent}%`,
                            icon: ReceiptText,
                            accent: 'sky',
                        },
                        {
                            key: 'remaining',
                            label:
                                summary.remaining !== null &&
                                summary.remaining < 0
                                    ? 'Over budget'
                                    : 'Remaining',
                            value:
                                summary.remaining === null
                                    ? '—'
                                    : formatMoney(Math.abs(summary.remaining)),
                            icon: PiggyBank,
                            accent:
                                summary.remaining !== null &&
                                summary.remaining < 0
                                    ? 'rose'
                                    : 'emerald',
                            valueClassName:
                                summary.remaining !== null &&
                                summary.remaining < 0
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : undefined,
                        },
                        {
                            key: 'allocated',
                            label: 'Given to programs',
                            value: formatMoney(summary.allocated),
                            hint: 'starting this year',
                            icon: WalletCards,
                            accent: 'violet',
                        },
                    ]}
                />

                <section className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                    <h2 className="text-sm font-semibold">
                        {summary.year} spend against budget
                    </h2>
                    <BudgetMeter
                        spent={summary.spent}
                        budget={summary.budget}
                    />
                    {summary.notes && (
                        <p className="text-sm text-muted-foreground">
                            {summary.notes}
                        </p>
                    )}
                </section>

                <div className="grid gap-3 lg:grid-cols-2">
                    <section className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                        <h2 className="text-sm font-semibold">
                            Spend by category
                        </h2>
                        {categories.length === 0 ? (
                            <p className="py-10 text-center text-sm text-muted-foreground">
                                Nothing spent in this year yet.
                            </p>
                        ) : (
                            <BarList
                                bars={categories.map(([category, amount]) => ({
                                    label: COST_CATEGORY_LABELS[category],
                                    value: amount,
                                }))}
                                format={formatMoney}
                            />
                        )}
                    </section>
                    <section className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                        <h2 className="text-sm font-semibold">
                            Spend by month
                        </h2>
                        <MonthColumns
                            values={summary.by_month}
                            currentMonth={
                                summary.year === now.getFullYear()
                                    ? now.getMonth()
                                    : null
                            }
                        />
                    </section>
                </div>

                <TableCard
                    title="Programs"
                    count={summary.programs.length}
                    description="Programs that spent this year or start in it."
                >
                    <DataTable>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Program</TableHead>
                                <TableHead className="hidden sm:table-cell">
                                    Status
                                </TableHead>
                                <TableHead className="text-right">
                                    Spent in {summary.year}
                                </TableHead>
                                <TableHead className="hidden w-64 md:table-cell">
                                    Against its budget
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {summary.programs.length === 0 && (
                                <EmptyTableRow
                                    colSpan={4}
                                    icon={Wallet}
                                    title="No program spent or started this year"
                                    description="Record costs on a program’s Costs tab, and set its budget when you create or edit it."
                                />
                            )}
                            {summary.programs.map((p) => {
                                const opens = rowOpens(
                                    `${trainingRoutes.show(p.hashid)}?tab=costs`,
                                );

                                return (
                                    <TableRow
                                        key={p.id}
                                        {...(p.archived ? {} : opens)}
                                        className={cn(
                                            !p.archived && opens.className,
                                        )}
                                    >
                                        <TableCell>
                                            {p.archived ? (
                                                <span className="text-sm text-muted-foreground">
                                                    {p.name} (archived)
                                                </span>
                                            ) : (
                                                <Link
                                                    href={`${trainingRoutes.show(p.hashid)}?tab=costs`}
                                                    className="text-sm font-medium hover:text-[#0ABFBF]"
                                                >
                                                    {p.name}
                                                </Link>
                                            )}
                                        </TableCell>
                                        <TableCell className="hidden sm:table-cell">
                                            <ProgramStatusBadge
                                                status={p.status}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right text-sm font-semibold tabular-nums">
                                            {formatMoney(p.spent)}
                                        </TableCell>
                                        <TableCell className="hidden md:table-cell">
                                            <BudgetMeter
                                                spent={p.spent_total}
                                                budget={p.budget}
                                            />
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </DataTable>
                </TableCard>

                <div className="flex flex-col gap-3">
                    <TableCard
                        title={`Costs in ${summary.year}`}
                        count={costs.length}
                    >
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Incurred</TableHead>
                                    <TableHead>Program</TableHead>
                                    <TableHead className="hidden sm:table-cell">
                                        For
                                    </TableHead>
                                    <TableHead className="hidden lg:table-cell">
                                        Details
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Amount
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {page.rows.length === 0 && (
                                    <EmptyTableRow
                                        colSpan={5}
                                        icon={ReceiptText}
                                        title="No costs recorded this year"
                                    />
                                )}
                                {page.rows.map((cost) => (
                                    <TableRow key={cost.id}>
                                        <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                            {formatDate(cost.incurred_on)}
                                        </TableCell>
                                        <TableCell>
                                            <p className="max-w-56 truncate text-sm">
                                                {cost.program.name}
                                            </p>
                                        </TableCell>
                                        <TableCell className="hidden text-sm sm:table-cell">
                                            {
                                                COST_CATEGORY_LABELS[
                                                    cost.category
                                                ]
                                            }
                                        </TableCell>
                                        <TableCell className="hidden lg:table-cell">
                                            <p className="max-w-72 truncate text-sm text-muted-foreground">
                                                {cost.description ?? '—'}
                                            </p>
                                        </TableCell>
                                        <TableCell className="text-right text-sm font-semibold tabular-nums">
                                            {formatMoney(cost.amount)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </DataTable>
                    </TableCard>
                    <TablePagination
                        meta={page.meta}
                        perPage={page.perPage}
                        onPage={page.setPage}
                        onPerPage={page.setPerPage}
                    />
                </div>
            </PageBody>

            <BudgetModal
                open={editing}
                onOpenChange={setEditing}
                year={summary.year}
                amount={summary.budget}
                notes={summary.notes}
            />
        </>
    );
}

function BudgetModal({
    open,
    onOpenChange,
    year,
    amount,
    notes,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    year: number;
    amount: number | null;
    notes: string | null;
}) {
    return (
        <Modal open={open} onOpenChange={onOpenChange}>
            <ModalContent size="sm">
                <ModalHeader
                    icon={
                        <ModalIcon>
                            <Wallet />
                        </ModalIcon>
                    }
                    title={`${year} training budget`}
                    description="What training may spend this year. Spend is added up from the costs recorded on each program."
                />
                {open && (
                    <BudgetForm
                        year={year}
                        amount={amount}
                        notes={notes}
                        onDone={() => onOpenChange(false)}
                    />
                )}
            </ModalContent>
        </Modal>
    );
}

function BudgetForm({
    year,
    amount,
    notes,
    onDone,
}: {
    year: number;
    amount: number | null;
    notes: string | null;
    onDone: () => void;
}) {
    const [value, setValue] = useState(amount === null ? '' : String(amount));
    const [text, setText] = useState(notes ?? '');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [processing, setProcessing] = useState(false);

    const submit = () =>
        setBudget(
            { year, amount: Number(value), notes: text.trim() || null },
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
                onError: setErrors,
            },
        );

    return (
        <>
            <ModalBody className="space-y-4">
                <FormField label="Amount (₱)" required error={errors.amount}>
                    <Input
                        type="number"
                        min="0"
                        step="0.01"
                        inputMode="decimal"
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        className="sm:w-56"
                    />
                </FormField>
                <FormField label="Notes" error={errors.notes}>
                    <textarea
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        rows={3}
                        maxLength={1000}
                        placeholder="e.g. Includes the compliance refreshers."
                        className={TEXTAREA}
                    />
                </FormField>
            </ModalBody>
            <ModalFooter>
                <Button
                    variant="outline"
                    onClick={onDone}
                    disabled={processing}
                >
                    Cancel
                </Button>
                <Button onClick={submit} disabled={processing || value === ''}>
                    {processing && <Spinner />}
                    Save budget
                </Button>
            </ModalFooter>
        </>
    );
}

TrainingBudget.layout = {
    breadcrumbs: [
        { title: 'Training & Development', href: '/training' },
        { title: 'Budget', href: '/training/budget' },
    ],
};
