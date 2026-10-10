import { Pencil, Plus, ReceiptText, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    rowOpens,
    TableCard,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { BarList } from '@/features/dashboard/components/charts';
import { cn } from '@/lib/utils';
import { deleteCost } from '../api';
import { COST_CATEGORY_LABELS, formatDate, formatMoney } from '../constants';
import type { CostCategory, ProgramMoney, TrainingCost } from '../types';
import { BudgetMeter } from './budget-meter';
import { CostFormModal } from './cost-form-modal';

/**
 * A program's money (ADR 0075): its budget against what it has spent, spend
 * per seat and per completion, where it went, and the costs behind it — which a
 * manager records, corrects and removes.
 */
export function CostsPanel({
    programHashid,
    money,
    costs,
    canManage,
}: {
    programHashid: string;
    money: ProgramMoney;
    costs: TrainingCost[];
    canManage: boolean;
}) {
    const [form, setForm] = useState<{
        open: boolean;
        cost: TrainingCost | null;
    }>({ open: false, cost: null });
    const [removing, setRemoving] = useState<TrainingCost | null>(null);
    const [processing, setProcessing] = useState(false);

    const categories = Object.entries(money.by_category) as [
        CostCategory,
        number,
    ][];

    const remove = () => {
        if (!removing) {
            return;
        }

        deleteCost(removing.id, {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setRemoving(null);
            },
        });
    };

    const record = () => setForm({ open: true, cost: null });

    return (
        <>
            <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <section className="flex flex-col gap-4 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                    <h2 className="text-sm font-semibold">Budget</h2>
                    <BudgetMeter spent={money.spent} budget={money.budget} />
                    <dl className="grid grid-cols-2 gap-3 text-sm">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Per participant
                            </dt>
                            <dd className="font-semibold tabular-nums">
                                {formatMoney(money.per_participant)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Per completion
                            </dt>
                            <dd className="font-semibold tabular-nums">
                                {formatMoney(money.per_completion)}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section className="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm dark:border-sidebar-border">
                    <h2 className="text-sm font-semibold">Where it went</h2>
                    {categories.length === 0 ? (
                        <p className="py-4 text-sm text-muted-foreground">
                            Nothing recorded yet.
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
            </div>

            <TableCard
                title="Costs"
                count={costs.length}
                actions={
                    canManage && (
                        <Button size="sm" onClick={record}>
                            <Plus className="size-4" />
                            Record a cost
                        </Button>
                    )
                }
            >
                <DataTable>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Incurred</TableHead>
                            <TableHead>For</TableHead>
                            <TableHead className="hidden md:table-cell">
                                Details
                            </TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            {canManage && <TableHead className="w-10" />}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {costs.length === 0 && (
                            <EmptyTableRow
                                colSpan={canManage ? 5 : 4}
                                icon={ReceiptText}
                                title="No costs recorded"
                                description={
                                    canManage
                                        ? 'Record trainer fees, venue, materials and the rest as they come in.'
                                        : 'Nothing has been spent on this program yet.'
                                }
                                action={
                                    canManage ? (
                                        <Button size="sm" onClick={record}>
                                            <Plus className="size-4" />
                                            Record a cost
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )}
                        {costs.map((cost) => {
                            const opens = rowOpens(() =>
                                setForm({ open: true, cost }),
                            );

                            return (
                                <TableRow
                                    key={cost.id}
                                    {...(canManage ? opens : {})}
                                    className={cn(canManage && opens.className)}
                                >
                                    <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                                        {formatDate(cost.incurred_on)}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {COST_CATEGORY_LABELS[cost.category]}
                                    </TableCell>
                                    <TableCell className="hidden md:table-cell">
                                        <p className="max-w-80 truncate text-sm text-muted-foreground">
                                            {cost.description ?? '—'}
                                        </p>
                                    </TableCell>
                                    <TableCell className="text-right text-sm font-semibold tabular-nums">
                                        {formatMoney(cost.amount)}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <RowMenuTrigger label="Cost actions" />
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent
                                                    align="end"
                                                    className="w-40"
                                                >
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            setForm({
                                                                open: true,
                                                                cost,
                                                            })
                                                        }
                                                    >
                                                        <Pencil className="size-4" />
                                                        Edit
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onSelect={() =>
                                                            setRemoving(cost)
                                                        }
                                                    >
                                                        <Trash2 className="size-4" />
                                                        Remove
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    )}
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </DataTable>
            </TableCard>

            <CostFormModal
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
                programHashid={programHashid}
                cost={form.cost}
            />

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title="Remove this cost?"
                description={`${removing ? formatMoney(removing.amount) : ''} comes off the program’s spend and its year’s.`}
                confirmLabel="Remove"
                destructive
                processing={processing}
                onConfirm={remove}
            />
        </>
    );
}
