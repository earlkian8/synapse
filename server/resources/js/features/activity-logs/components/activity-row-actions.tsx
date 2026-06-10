import { Eye, MoreHorizontal, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { ActivityLogEntry } from '../types';

type Props = {
    log: ActivityLogEntry;
    onView: (log: ActivityLogEntry) => void;
    onDelete: (log: ActivityLogEntry) => void;
};

export function ActivityRowActions({ log, onView, onDelete }: Props) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 text-muted-foreground data-[state=open]:bg-muted"
                    aria-label="Log actions"
                >
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    Log entry
                </DropdownMenuLabel>
                <DropdownMenuItem onSelect={() => onView(log)}>
                    <Eye className="size-4" />
                    View details
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    onSelect={() => onDelete(log)}
                    variant="destructive"
                >
                    <Trash2 className="size-4" />
                    Delete
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
