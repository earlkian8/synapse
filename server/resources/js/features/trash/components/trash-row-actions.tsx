import { MoreHorizontal, RotateCcw, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { TrashItem } from '../types';

type Props = {
    item: TrashItem;
    onRestore: (item: TrashItem) => void;
    onDelete: (item: TrashItem) => void;
};

export function TrashRowActions({ item, onRestore, onDelete }: Props) {
    if (!item.can_restore && !item.can_force_delete) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 text-muted-foreground data-[state=open]:bg-muted"
                    aria-label={`Actions for ${item.title}`}
                >
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
                {item.can_restore && (
                    <DropdownMenuItem onSelect={() => onRestore(item)}>
                        <RotateCcw className="size-4" />
                        Restore
                    </DropdownMenuItem>
                )}
                {item.can_force_delete && (
                    <>
                        {item.can_restore && <DropdownMenuSeparator />}
                        <DropdownMenuItem
                            variant="destructive"
                            onSelect={() => onDelete(item)}
                        >
                            <Trash2 className="size-4" />
                            Delete permanently
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
