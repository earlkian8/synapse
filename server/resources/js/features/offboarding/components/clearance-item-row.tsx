import {
    CircleDashed,
    Flag,
    MoreHorizontal,
    Pencil,
    TriangleAlert,
    Trash2,
} from 'lucide-react';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { ClearanceItem, ClearanceStatus } from '../types';

type Props = {
    item: ClearanceItem;
    canManage: boolean;
    onToggle: (item: ClearanceItem, status: ClearanceStatus) => void;
    onEdit: (item: ClearanceItem) => void;
    onDelete: (item: ClearanceItem) => void;
};

export function ClearanceItemRow({
    item,
    canManage,
    onToggle,
    onEdit,
    onDelete,
}: Props) {
    const cleared = item.status === 'cleared';
    const flagged = item.status === 'flagged';

    return (
        <div className="flex items-start gap-3 px-3 py-2.5">
            <Checkbox
                checked={cleared}
                disabled={!canManage}
                onCheckedChange={(checked) =>
                    onToggle(item, checked ? 'cleared' : 'pending')
                }
                className="mt-0.5"
                aria-label={cleared ? 'Mark not cleared' : 'Mark cleared'}
            />

            <div className="min-w-0 flex-1">
                <p
                    className={cn(
                        'text-sm font-medium',
                        cleared && 'text-muted-foreground line-through',
                    )}
                >
                    {item.item}
                </p>
                <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-muted-foreground">
                    {cleared && item.cleared_human && (
                        <span className="inline-flex items-center gap-1">
                            <CircleDashed className="size-3" />
                            Cleared {item.cleared_human}
                            {item.cleared_by ? ` · ${item.cleared_by}` : ''}
                        </span>
                    )}
                    {flagged && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-rose-500/10 px-1.5 py-0.5 font-medium text-rose-600 dark:text-rose-400">
                            <TriangleAlert className="size-3" />
                            Flagged
                        </span>
                    )}
                </div>
                {item.remarks && (
                    <p
                        className={cn(
                            'mt-1 text-xs',
                            flagged
                                ? 'text-rose-600 dark:text-rose-400'
                                : 'text-muted-foreground',
                        )}
                    >
                        {item.remarks}
                    </p>
                )}
            </div>

            {canManage && (
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <button
                            type="button"
                            className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted"
                            aria-label="Clearance actions"
                        >
                            <MoreHorizontal className="size-4" />
                        </button>
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
        </div>
    );
}
