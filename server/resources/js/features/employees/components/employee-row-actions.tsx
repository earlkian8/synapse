import {
    ArchiveRestore,
    Eye,
    MailX,
    MoreHorizontal,
    Pencil,
    Send,
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
    onInvite: (employee: ManagedEmployee) => void;
    onRevokeInvite: (employee: ManagedEmployee) => void;
    onArchive: (employee: ManagedEmployee) => void;
    onRestore: (employee: ManagedEmployee) => void;
    onDelete: (employee: ManagedEmployee) => void;
};

export function EmployeeRowActions({
    employee,
    can,
    onView,
    onEdit,
    onInvite,
    onRevokeInvite,
    onArchive,
    onRestore,
    onDelete,
}: Props) {
    const isArchived = employee.status === 'archived';
    const access = employee.app_access;

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
                        {/* HR invites; it never sets a password (ADR 0026). The
                            wording tracks where the person actually is, so the
                            menu never offers to "invite" somebody already in. */}
                        {can.invite && access === 'none' && (
                            <DropdownMenuItem
                                onSelect={() => onInvite(employee)}
                            >
                                <Send className="size-4" />
                                Invite to the app
                            </DropdownMenuItem>
                        )}
                        {can.invite && access === 'invited' && (
                            <>
                                <DropdownMenuItem
                                    onSelect={() => onInvite(employee)}
                                >
                                    <Send className="size-4" />
                                    Resend invitation
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onSelect={() => onRevokeInvite(employee)}
                                >
                                    <MailX className="size-4" />
                                    Revoke invitation
                                </DropdownMenuItem>
                            </>
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
