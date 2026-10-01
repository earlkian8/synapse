import { Archive, MoreHorizontal, Pencil } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { LeaveType } from '../types';

type Props = {
    type: LeaveType;
    canManage: boolean;
    onEdit: (type: LeaveType) => void;
    onArchive: (type: LeaveType) => void;
};

export function LeaveTypeCard({ type, canManage, onEdit, onArchive }: Props) {
    return (
        <div
            className={cn(
                'relative overflow-hidden rounded-xl border border-sidebar-border/70 bg-card p-4 shadow-sm transition-shadow hover:shadow-md dark:border-sidebar-border',
                !type.is_active && 'opacity-60',
            )}
        >
            {/* Colour accent */}
            <span
                className="absolute inset-y-0 left-0 w-1"
                style={{ backgroundColor: type.color }}
            />

            <div className="flex items-start justify-between gap-2 pl-2">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <h3 className="truncate text-sm font-semibold">
                            {type.name}
                        </h3>
                        <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold tracking-wide text-muted-foreground">
                            {type.code}
                        </span>
                    </div>
                    <p className="mt-1 text-sm text-muted-foreground tabular-nums">
                        {type.default_days} day
                        {type.default_days === 1 ? '' : 's'} / year
                    </p>
                </div>

                {canManage && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted"
                                aria-label="Leave type actions"
                            >
                                <MoreHorizontal className="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" className="w-36">
                            <DropdownMenuItem onSelect={() => onEdit(type)}>
                                <Pencil className="size-4" />
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                variant="destructive"
                                onSelect={() => onArchive(type)}
                            >
                                <Archive className="size-4" />
                                Archive
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>

            {type.description && (
                <p className="mt-2 line-clamp-2 pl-2 text-xs text-muted-foreground">
                    {type.description}
                </p>
            )}

            <div className="mt-3 flex flex-wrap gap-1.5 pl-2">
                <Flag>{type.is_paid ? 'Paid' : 'Unpaid'}</Flag>
                {type.allow_half_day && <Flag>Half-day</Flag>}
                <Flag>
                    {type.requires_approval
                        ? 'Needs approval'
                        : 'Auto-approved'}
                </Flag>
                {!type.is_active && <Flag muted>Inactive</Flag>}
            </div>
        </div>
    );
}

function Flag({
    children,
    muted = false,
}: {
    children: React.ReactNode;
    muted?: boolean;
}) {
    return (
        <Badge
            variant="outline"
            className={cn(
                'rounded-full px-2 py-0 text-[10px] font-medium',
                muted && 'text-muted-foreground',
            )}
        >
            {children}
        </Badge>
    );
}
