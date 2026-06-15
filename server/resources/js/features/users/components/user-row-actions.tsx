import {
    ArchiveRestore,
    Eye,
    KeyRound,
    MailCheck,
    MoreHorizontal,
    Pencil,
    Power,
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
import type { ManagedUser, UserPermissions } from '../types';

type Props = {
    user: ManagedUser;
    can: UserPermissions;
    onView: (user: ManagedUser) => void;
    onEdit: (user: ManagedUser) => void;
    onToggleStatus: (user: ManagedUser) => void;
    onResetPassword: (user: ManagedUser) => void;
    onResendVerification: (user: ManagedUser) => void;
    onArchive: (user: ManagedUser) => void;
    onRestore: (user: ManagedUser) => void;
    onDelete: (user: ManagedUser) => void;
};

export function UserRowActions({
    user,
    can,
    onView,
    onEdit,
    onToggleStatus,
    onResetPassword,
    onResendVerification,
    onArchive,
    onRestore,
    onDelete,
}: Props) {
    const isArchived = user.status === 'archived';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 text-muted-foreground data-[state=open]:bg-muted"
                    aria-label={`Actions for ${user.full_name}`}
                >
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-48">
                <DropdownMenuLabel className="text-xs text-muted-foreground">
                    Manage user
                </DropdownMenuLabel>
                <DropdownMenuItem onSelect={() => onView(user)}>
                    <Eye className="size-4" />
                    View profile
                </DropdownMenuItem>

                {!isArchived && (
                    <>
                        {can.update && (
                            <DropdownMenuItem onSelect={() => onEdit(user)}>
                                <Pencil className="size-4" />
                                Edit
                            </DropdownMenuItem>
                        )}
                        {can.manageStatus && (
                            <DropdownMenuItem
                                onSelect={() => onToggleStatus(user)}
                            >
                                <Power className="size-4" />
                                {user.is_active ? 'Deactivate' : 'Activate'}
                            </DropdownMenuItem>
                        )}
                        {can.resetPassword && (
                            <DropdownMenuItem
                                onSelect={() => onResetPassword(user)}
                            >
                                <KeyRound className="size-4" />
                                Reset password
                            </DropdownMenuItem>
                        )}
                        {can.update && !user.email_verified && (
                            <DropdownMenuItem
                                onSelect={() => onResendVerification(user)}
                            >
                                <MailCheck className="size-4" />
                                Resend verification
                            </DropdownMenuItem>
                        )}
                        {can.delete && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => onArchive(user)}
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
                            <DropdownMenuItem onSelect={() => onRestore(user)}>
                                <ArchiveRestore className="size-4" />
                                Restore
                            </DropdownMenuItem>
                        )}
                        {can.forceDelete && (
                            <>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    onSelect={() => onDelete(user)}
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
