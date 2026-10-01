import { Trash2 } from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { TYPE_META, trashKey } from '../constants';
import type { TrashItem } from '../types';
import { TrashRowActions } from './trash-row-actions';

type Props = {
    items: TrashItem[];
    selected: string[];
    onToggleAll: (checked: boolean) => void;
    onToggleRow: (key: string, checked: boolean) => void;
    onRestore: (item: TrashItem) => void;
    onDelete: (item: TrashItem) => void;
};

export function TrashTable({
    items,
    selected,
    onToggleAll,
    onToggleRow,
    onRestore,
    onDelete,
}: Props) {
    const allSelected = items.length > 0 && selected.length === items.length;
    const someSelected = selected.length > 0 && !allSelected;

    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <Table>
                <TableHeader className="bg-muted/40">
                    <TableRow className="hover:bg-transparent">
                        <TableHead className="w-10 pl-4">
                            <Checkbox
                                checked={
                                    allSelected
                                        ? true
                                        : someSelected
                                          ? 'indeterminate'
                                          : false
                                }
                                onCheckedChange={(value) =>
                                    onToggleAll(value === true)
                                }
                                aria-label="Select all"
                            />
                        </TableHead>
                        <TableHead>Item</TableHead>
                        <TableHead>Type</TableHead>
                        <TableHead>Archived</TableHead>
                        <TableHead className="w-10 pr-4" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {items.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={5} className="py-16">
                                <div className="flex flex-col items-center justify-center gap-2 text-center">
                                    <span className="flex size-12 items-center justify-center rounded-full bg-muted">
                                        <Trash2 className="size-6 text-muted-foreground" />
                                    </span>
                                    <p className="text-sm font-medium">
                                        Trash is empty
                                    </p>
                                    <p className="max-w-xs text-sm text-muted-foreground">
                                        Archived records show up here, where you
                                        can restore them or delete them for
                                        good.
                                    </p>
                                </div>
                            </TableCell>
                        </TableRow>
                    )}

                    {items.map((item) => {
                        const key = trashKey(item);
                        const isSelected = selected.includes(key);

                        return (
                            <TableRow
                                key={key}
                                data-state={isSelected ? 'selected' : undefined}
                            >
                                <TableCell className="pl-4">
                                    <Checkbox
                                        checked={isSelected}
                                        onCheckedChange={(value) =>
                                            onToggleRow(key, value === true)
                                        }
                                        aria-label={`Select ${item.title}`}
                                    />
                                </TableCell>
                                <TableCell>
                                    <div className="flex items-center gap-3">
                                        <ItemAvatar item={item} />
                                        <div className="min-w-0">
                                            <p className="truncate font-medium">
                                                {item.title}
                                            </p>
                                            {item.subtitle && (
                                                <p className="truncate text-xs text-muted-foreground">
                                                    {item.subtitle}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <span
                                        className={cn(
                                            'inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium',
                                            TYPE_META[item.type].badge,
                                        )}
                                    >
                                        {item.type_label}
                                    </span>
                                </TableCell>
                                <TableCell className="text-sm text-muted-foreground">
                                    {item.deleted_human ?? '—'}
                                </TableCell>
                                <TableCell className="pr-4 text-right">
                                    <TrashRowActions
                                        item={item}
                                        onRestore={onRestore}
                                        onDelete={onDelete}
                                    />
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}

function ItemAvatar({ item }: { item: TrashItem }) {
    const Icon = TYPE_META[item.type].icon;

    if (item.avatar?.photo) {
        return (
            <img
                src={item.avatar.photo}
                alt=""
                className="size-9 shrink-0 rounded-full object-cover ring-1 ring-border"
            />
        );
    }

    if (item.avatar?.initials) {
        return (
            <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#0F2044] text-xs font-semibold text-white ring-1 ring-border">
                {item.avatar.initials}
            </span>
        );
    }

    return (
        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground ring-1 ring-border">
            <Icon className="size-4" />
        </span>
    );
}
