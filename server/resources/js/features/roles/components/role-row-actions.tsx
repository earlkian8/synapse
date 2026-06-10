import { Eye, MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { ManagedRole } from '../types';

type Props = {
    role: ManagedRole;
    canUpdate: boolean;
    canDelete: boolean;
    onView: (role: ManagedRole) => void;
    onEdit: (role: ManagedRole) => void;
    onDelete: (role: ManagedRole) => void;
};

export function RoleRowActions({
    role,
    canUpdate,
    canDelete,
    onView,
    onEdit,
    onDelete,
}: Props) {
    const editable = canUpdate && !role.is_super_admin;
    const deletable = canDelete && !role.is_system;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 text-muted-foreground data-[state=open]:bg-muted"
                    aria-label={`Actions for ${role.label}`}
                >
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    Manage role
                </DropdownMenuLabel>
                <DropdownMenuItem onSelect={() => onView(role)}>
                    <Eye className="size-4" />
                    View permissions
                </DropdownMenuItem>

                {editable && (
                    <DropdownMenuItem onSelect={() => onEdit(role)}>
                        <Pencil className="size-4" />
                        Edit
                    </DropdownMenuItem>
                )}

                {deletable && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onSelect={() => onDelete(role)}
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
