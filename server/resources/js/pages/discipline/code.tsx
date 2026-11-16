import { Head, router, usePage } from '@inertiajs/react';
import { BookOpenCheck, ListPlus, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DataTable,
    EmptyTableRow,
    PageBody,
    PageHeader,
    RowMenuTrigger,
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
import { GravityMark } from '@/features/discipline/components/case-badges';
import { DisciplineNav } from '@/features/discipline/components/discipline-nav';
import { OffenseFormDialog } from '@/features/discipline/components/offense-form-dialog';
import { penaltyStep } from '@/features/discipline/constants';
import { disciplineRoutes } from '@/features/discipline/routes';
import type { Gravity, Offense, Penalty } from '@/features/discipline/types';

type Props = {
    offenses: Offense[];
    options: {
        gravities: Record<Gravity, string>;
        grounds: Record<string, string>;
        justCauses: string[];
        penalties: Record<Penalty, string>;
    };
};

/**
 * Discipline → Code of conduct (ADR 0105): every offense a case can be charged
 * under, how grave, the ground it rests on, and its ladder of penalties.
 */
export default function ConductCode() {
    const { offenses, options } = usePage<Props>().props;
    const [form, setForm] = useState<{
        open: boolean;
        offense: Offense | null;
    }>({ open: false, offense: null });
    const [removing, setRemoving] = useState<Offense | null>(null);
    const [processing, setProcessing] = useState(false);

    const addStandard = () =>
        router.post(
            disciplineRoutes.codeStandard,
            {},
            { preserveScroll: true },
        );

    return (
        <>
            <Head title="Code of conduct" />

            <PageBody>
                <PageHeader
                    title="Code of conduct"
                    description="The offenses a case is charged under. The penalty for a repeat follows the ladder, counting cases decided within each offense’s period."
                    actions={<DisciplineNav current="code" />}
                />

                <TableCard
                    title="Offenses"
                    count={offenses.length}
                    actions={
                        <>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={addStandard}
                            >
                                <ListPlus className="size-4" />
                                Add the standard offenses
                            </Button>
                            <Button
                                size="sm"
                                onClick={() =>
                                    setForm({ open: true, offense: null })
                                }
                            >
                                <Plus className="size-4" />
                                Add offense
                            </Button>
                        </>
                    }
                >
                    <DataTable>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Offense</TableHead>
                                <TableHead>Ground</TableHead>
                                <TableHead>
                                    Penalties, time after time
                                </TableHead>
                                <TableHead className="w-10">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {offenses.length === 0 && (
                                <EmptyTableRow
                                    colSpan={4}
                                    icon={BookOpenCheck}
                                    title="No offenses yet"
                                    description="Start from the offenses most Philippine handbooks list — tardiness, AWOL, insubordination, dishonesty and more — then edit them to match yours."
                                    action={
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={addStandard}
                                        >
                                            <ListPlus className="size-4" />
                                            Add the standard offenses
                                        </Button>
                                    }
                                />
                            )}
                            {offenses.map((offense) => (
                                <TableRow
                                    key={offense.id}
                                    className={
                                        offense.is_active
                                            ? undefined
                                            : 'opacity-60'
                                    }
                                >
                                    <TableCell className="max-w-80">
                                        <div className="flex items-start gap-2.5">
                                            <span className="pt-1">
                                                <GravityMark
                                                    gravity={offense.gravity}
                                                />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="font-medium">
                                                    <span className="text-muted-foreground tabular-nums">
                                                        {offense.code}
                                                    </span>{' '}
                                                    {offense.title}
                                                </p>
                                                {offense.description && (
                                                    <p className="line-clamp-2 text-xs text-muted-foreground">
                                                        {offense.description}
                                                    </p>
                                                )}
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell className="max-w-56 text-sm">
                                        {offense.ground_label}
                                        <span className="block text-xs text-muted-foreground">
                                            Counts for{' '}
                                            {offense.reckoning_months} months
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <ol className="flex flex-wrap items-center gap-1 text-xs">
                                            {offense.penalties.map(
                                                (step, index) => (
                                                    <li
                                                        key={index}
                                                        className="flex items-center gap-1"
                                                    >
                                                        {index > 0 && (
                                                            <span
                                                                aria-hidden
                                                                className="text-muted-foreground"
                                                            >
                                                                ›
                                                            </span>
                                                        )}
                                                        <span
                                                            className={
                                                                step.action ===
                                                                'dismissal'
                                                                    ? 'rounded border border-rose-500/30 bg-rose-500/10 px-1.5 py-0.5 text-rose-700 dark:text-rose-300'
                                                                    : 'rounded border px-1.5 py-0.5'
                                                            }
                                                        >
                                                            {penaltyStep(step)}
                                                        </span>
                                                    </li>
                                                ),
                                            )}
                                        </ol>
                                    </TableCell>
                                    <TableCell>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <RowMenuTrigger
                                                    label={`Actions for ${offense.title}`}
                                                />
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                className="w-44"
                                            >
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        setForm({
                                                            open: true,
                                                            offense,
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
                                                        setRemoving(offense)
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                    Remove
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </DataTable>
                </TableCard>
            </PageBody>

            <OffenseFormDialog
                offense={form.offense}
                open={form.open}
                onOpenChange={(open) => setForm((prev) => ({ ...prev, open }))}
                options={options}
            />

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Remove ${removing?.code} ${removing?.title}?`}
                description="New cases can’t be charged under it. Cases already charged under it keep naming it."
                confirmLabel="Remove offense"
                destructive
                processing={processing}
                onConfirm={() =>
                    removing &&
                    router.delete(disciplineRoutes.codeUpdate(removing.id), {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => {
                            setProcessing(false);
                            setRemoving(null);
                        },
                    })
                }
            />
        </>
    );
}

ConductCode.layout = {
    breadcrumbs: [
        { title: 'Discipline', href: disciplineRoutes.index },
        { title: 'Code of conduct', href: disciplineRoutes.code },
    ],
};
