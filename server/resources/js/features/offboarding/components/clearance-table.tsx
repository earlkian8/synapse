import {
    Building2,
    CheckCheck,
    CircleDashed,
    ClipboardCheck,
    Flag,
    Pencil,
    Plus,
    Trash2,
} from 'lucide-react';
import { Fragment, useMemo } from 'react';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    rowOpens,
    TableCard,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { cn } from '@/lib/utils';
import {
    CLEARANCE_STATUS_LABELS,
    CLEARANCE_STATUS_STYLES,
    UNASSIGNED_DEPARTMENT,
} from '../constants';
import type { ClearanceItem, ClearanceStatus } from '../types';

type Props = {
    items: ClearanceItem[];
    canManage: boolean;
    onToggle: (item: ClearanceItem, status: ClearanceStatus) => void;
    onEdit: (item: ClearanceItem) => void;
    onDelete: (item: ClearanceItem) => void;
    onAdd: () => void;
    /** Sign off a whole department group (null = the unassigned group). */
    onClearGroup: (departmentId: number | null, name: string) => void;
};

type Group = {
    key: string;
    name: string;
    departmentId: number | null;
    items: ClearanceItem[];
};

const COLUMNS = 6;

/**
 * The clearance checklist as one table: a header row per department that signs
 * items off (unassigned last, each with "Clear all" for its pending items),
 * then a row per item — tick it off, see who signed and when, and why anything
 * is flagged. For someone who manages offboarding, a row opens the item to edit.
 */
export function ClearanceTable({
    items,
    canManage,
    onToggle,
    onEdit,
    onDelete,
    onAdd,
    onClearGroup,
}: Props) {
    const groups = useMemo(() => {
        const byDepartment = new Map<string, Group>();

        for (const item of items) {
            const key = item.department
                ? `d${item.department.id}`
                : 'unassigned';
            const group = byDepartment.get(key) ?? {
                key,
                name: item.department?.name ?? UNASSIGNED_DEPARTMENT,
                departmentId: item.department?.id ?? null,
                items: [],
            };
            group.items.push(item);
            byDepartment.set(key, group);
        }

        // Departments alphabetically, with Unassigned always last.
        return [...byDepartment.values()].sort((a, b) => {
            if (a.key === 'unassigned') {
                return 1;
            }

            if (b.key === 'unassigned') {
                return -1;
            }

            return a.name.localeCompare(b.name);
        });
    }, [items]);

    return (
        <TableCard
            title="Clearance checklist"
            count={items.length}
            description="Grouped by the department that signs each item off."
        >
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-10">
                            <span className="sr-only">Cleared</span>
                        </TableHead>
                        <TableHead>Item</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Signed off</TableHead>
                        <TableHead>Remarks</TableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {items.length === 0 && (
                        <EmptyTableRow
                            colSpan={COLUMNS}
                            icon={ClipboardCheck}
                            title="No clearance items yet"
                            description="Add the first sign-off, or add a template's items from the actions menu."
                            action={
                                canManage && (
                                    <Button size="sm" onClick={onAdd}>
                                        <Plus className="size-4" />
                                        Add item
                                    </Button>
                                )
                            }
                        />
                    )}

                    {groups.map((group) => {
                        const cleared = group.items.filter(
                            (item) => item.is_cleared,
                        ).length;
                        const pending = group.items.filter(
                            (item) => item.status === 'pending',
                        ).length;

                        return (
                            <Fragment key={group.key}>
                                <TableRow className="bg-muted/30 hover:bg-muted/30">
                                    <TableCell
                                        colSpan={COLUMNS}
                                        className="py-1"
                                    >
                                        <span className="flex min-h-7 items-center gap-2">
                                            <span className="flex size-5 items-center justify-center rounded-md bg-[#0ABFBF]/10 text-[#0ABFBF]">
                                                <Building2 className="size-3" />
                                            </span>
                                            <span className="text-xs font-semibold">
                                                {group.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground tabular-nums">
                                                {cleared}/{group.items.length}{' '}
                                                cleared
                                            </span>
                                            {canManage && pending > 0 && (
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="ml-auto h-7 text-xs text-muted-foreground hover:text-foreground"
                                                    onClick={() =>
                                                        onClearGroup(
                                                            group.departmentId,
                                                            group.name,
                                                        )
                                                    }
                                                >
                                                    <CheckCheck className="size-3.5" />
                                                    Clear all
                                                    <span className="tabular-nums">
                                                        ({pending})
                                                    </span>
                                                </Button>
                                            )}
                                        </span>
                                    </TableCell>
                                </TableRow>
                                {group.items.map((item) => (
                                    <ItemRow
                                        key={item.id}
                                        item={item}
                                        canManage={canManage}
                                        onToggle={onToggle}
                                        onEdit={onEdit}
                                        onDelete={onDelete}
                                    />
                                ))}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}

function ItemRow({
    item,
    canManage,
    onToggle,
    onEdit,
    onDelete,
}: {
    item: ClearanceItem;
    canManage: boolean;
    onToggle: (item: ClearanceItem, status: ClearanceStatus) => void;
    onEdit: (item: ClearanceItem) => void;
    onDelete: (item: ClearanceItem) => void;
}) {
    const cleared = item.status === 'cleared';
    const flagged = item.status === 'flagged';

    return (
        <TableRow {...(canManage ? rowOpens(() => onEdit(item)) : {})}>
            <TableCell>
                <Checkbox
                    checked={cleared}
                    disabled={!canManage}
                    onCheckedChange={(checked) =>
                        onToggle(item, checked ? 'cleared' : 'pending')
                    }
                    aria-label={
                        cleared
                            ? `Mark “${item.item}” not cleared`
                            : `Mark “${item.item}” cleared`
                    }
                />
            </TableCell>
            <TableCell className="min-w-56 whitespace-normal">
                <span
                    className={cn(
                        'text-sm font-medium',
                        cleared && 'text-muted-foreground line-through',
                    )}
                >
                    {item.item}
                </span>
            </TableCell>
            <TableCell>
                <span
                    className={cn(
                        'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium whitespace-nowrap',
                        CLEARANCE_STATUS_STYLES[item.status],
                    )}
                >
                    {CLEARANCE_STATUS_LABELS[item.status]}
                </span>
            </TableCell>
            <TableCell className="text-xs whitespace-nowrap text-muted-foreground">
                {cleared && item.cleared_human ? (
                    <>
                        {item.cleared_human}
                        {item.cleared_by && (
                            <span className="block">{item.cleared_by}</span>
                        )}
                    </>
                ) : (
                    '—'
                )}
            </TableCell>
            <TableCell className="min-w-48 whitespace-normal">
                {item.remarks ? (
                    <span
                        className={cn(
                            'line-clamp-2 text-xs',
                            flagged
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-muted-foreground',
                        )}
                    >
                        {item.remarks}
                    </span>
                ) : (
                    <span className="text-xs text-muted-foreground">—</span>
                )}
            </TableCell>
            <TableCell className="text-right">
                {canManage && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <RowMenuTrigger
                                label={`Actions for “${item.item}”`}
                            />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-44">
                            {!flagged ? (
                                <DropdownMenuItem
                                    onSelect={() => onToggle(item, 'flagged')}
                                >
                                    <Flag className="size-4" />
                                    Flag an issue
                                </DropdownMenuItem>
                            ) : (
                                <DropdownMenuItem
                                    onSelect={() => onToggle(item, 'pending')}
                                >
                                    <CircleDashed className="size-4" />
                                    Clear flag
                                </DropdownMenuItem>
                            )}
                            <DropdownMenuItem onSelect={() => onEdit(item)}>
                                <Pencil className="size-4" />
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => onDelete(item)}
                            >
                                <Trash2 className="size-4" />
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </TableCell>
        </TableRow>
    );
}
