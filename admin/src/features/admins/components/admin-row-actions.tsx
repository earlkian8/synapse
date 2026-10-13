import {
    KeyRound,
    Pencil,
    Power,
    PowerOff,
    Smartphone,
    Trash2,
} from 'lucide-react';
import { RowMenuTrigger } from '@/components/data-table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Admin } from '@/lib/types';

export type AdminAction =
    | 'edit'
    | 'reset-password'
    | 'reset-two-factor'
    | 'deactivate'
    | 'activate'
    | 'delete';

/**
 * What can be done to an admin from their row. One's own row offers only Edit:
 * the server refuses the rest, and one's password lives on the account page.
 */
export function AdminRowActions({
    admin,
    onAction,
}: {
    admin: Admin;
    onAction: (action: AdminAction, admin: Admin) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <RowMenuTrigger label={`Actions for ${admin.name}`} />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuItem onSelect={() => onAction('edit', admin)}>
                    <Pencil />
                    Edit
                </DropdownMenuItem>
                {!admin.is_self && (
                    <>
                        <DropdownMenuItem
                            onSelect={() => onAction('reset-password', admin)}
                        >
                            <KeyRound />
                            Reset password
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onSelect={() => onAction('reset-two-factor', admin)}
                            disabled={!admin.two_factor_enabled}
                        >
                            <Smartphone />
                            Reset authenticator
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        {admin.is_active ? (
                            <DropdownMenuItem
                                onSelect={() => onAction('deactivate', admin)}
                            >
                                <PowerOff />
                                Deactivate
                            </DropdownMenuItem>
                        ) : (
                            <>
                                <DropdownMenuItem
                                    onSelect={() => onAction('activate', admin)}
                                >
                                    <Power />
                                    Activate
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    variant="destructive"
                                    onSelect={() => onAction('delete', admin)}
                                >
                                    <Trash2 />
                                    Delete
                                </DropdownMenuItem>
                            </>
                        )}
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
