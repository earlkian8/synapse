import {
    KanbanSquare,
    MoreHorizontal,
    Pencil,
    SlidersHorizontal,
    Trash2,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { POSTING_STATUS_OPTIONS } from '../constants';
import type { ManagedPosting, RecruitmentPermissions } from '../types';

type Props = {
    posting: ManagedPosting;
    can: RecruitmentPermissions;
    onOpen: (posting: ManagedPosting) => void;
    onEdit: (posting: ManagedPosting) => void;
    onStatus: (posting: ManagedPosting, status: string) => void;
    onDelete: (posting: ManagedPosting) => void;
};

export function PostingRowActions({
    posting,
    can,
    onOpen,
    onEdit,
    onStatus,
    onDelete,
}: Props) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 text-muted-foreground data-[state=open]:bg-muted"
                    aria-label={`Actions for ${posting.title}`}
                >
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    Manage posting
                </DropdownMenuLabel>
                <DropdownMenuItem onSelect={() => onOpen(posting)}>
                    <KanbanSquare className="size-4" />
                    Open pipeline
                </DropdownMenuItem>

                {can.update && (
                    <>
                        <DropdownMenuItem onSelect={() => onEdit(posting)}>
                            <Pencil className="size-4" />
                            Edit
                        </DropdownMenuItem>
                        <DropdownMenuSub>
                            <DropdownMenuSubTrigger>
                                <SlidersHorizontal className="size-4" />
                                Set status
                            </DropdownMenuSubTrigger>
                            <DropdownMenuSubContent>
                                {POSTING_STATUS_OPTIONS.map((option) => (
                                    <DropdownMenuItem
                                        key={option.value}
                                        disabled={
                                            posting.status === option.value
                                        }
                                        onSelect={() =>
                                            onStatus(posting, option.value)
                                        }
                                    >
                                        {option.label}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuSubContent>
                        </DropdownMenuSub>
                    </>
                )}

                {can.delete && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => onDelete(posting)}
                            variant="destructive"
                        >
                            <Trash2 className="size-4" />
                            Delete
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
