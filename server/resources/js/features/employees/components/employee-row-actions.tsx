import {
    ArchiveRestore,
    Eye,
    KeyRound,
    MoreHorizontal,
    Pencil,
    Trash2,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { EmployeePermissions, ManagedEmployee } from '../types';

type Props = {
    employee: ManagedEmployee;
    can: EmployeePermissions;
    onView: (employee: ManagedEmployee) => void;
    onEdit: (employee: ManagedEmployee) => void;
    onResetPassword: (employee: ManagedEmployee) => void;
    onArchive: (employee: ManagedEmployee) => void;
    onRestore: (employee: ManagedEmployee) => void;
    onDelete: (employee: ManagedEmployee) => void;
};

export function EmployeeRowActions({
    employee,
    can,
    onView,
    onEdit,
    onResetPassword,
    onArchive,
    onRestore,
    onDelete,
}: Props) {
    const isArchived = employee.status === 'archived';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 text-muted-foreground data-[state=open]:bg-muted"
                    aria-label={`Actions for ${employee.full_name}`}
                >
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    Manage employee
                </DropdownMenuLabel>
                <DropdownMenuItem onSelect={() => onView(employee)}>
                    <Eye className="size-4" />
                    View profile
                </DropdownMenuItem>

                {!isArchived && (
                    <>
                        {can.update && (
                            <DropdownMenuItem onSelect={() => onEdit(employee)}>
                                <Pencil className="size-4" />
                                Edit
                            </DropdownMenuItem>
                        )}
                        {can.update && (
                            <DropdownMenuItem
                                onSelect={() => onResetPassword(employee)}
                            >
                                <KeyRound className="size-4" />
                                Reset password
                            </DropdownMenuItem>
                        )}
                        {can.delete && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => onArchive(employee)}
                                    variant="destructive"
                                >
                                    <Trash2 className="size-4" />
                                    Archive
                                </DropdownMenuItem>
                            </>
                        )}
                    </>
                )}

                {isArchived && (
                    <>
                        {can.restore && (
                            <DropdownMenuItem
                                onSelect={() => onRestore(employee)}
                            >
                                <ArchiveRestore className="size-4" />
                                Restore
                            </DropdownMenuItem>
                        )}
                        {can.forceDelete && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => onDelete(employee)}
                                    variant="destructive"
                                >
                                    <Trash2 className="size-4" />
                                    Delete permanently
                                </DropdownMenuItem>
                            </>
                        )}
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
