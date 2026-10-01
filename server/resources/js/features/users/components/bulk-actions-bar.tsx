import {
    Archive,
    ArchiveRestore,
    CheckCircle2,
    PauseCircle,
    ShieldCheck,
    Trash2,
    X,
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
import type { BulkAction, UserPermissions, UserRole } from '../types';

type Props = {
    count: number;
    scope: string;
    can: UserPermissions;
    roles: UserRole[];
    onAction: (action: BulkAction) => void;
    onAssignRole: (roleId: number) => void;
    onClear: () => void;
};

export function BulkActionsBar({
    count,
    scope,
    can,
    roles,
    onAction,
    onAssignRole,
    onClear,
}: Props) {
    const isArchivedScope = scope === 'archived';

    return (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-[#0ABFBF]/30 bg-[#0ABFBF]/5 px-3 py-2 shadow-sm">
            <span className="inline-flex items-center gap-2 px-1 text-sm font-medium">
                <span className="flex size-6 items-center justify-center rounded-full bg-[#0ABFBF] text-xs font-semibold text-white tabular-nums">
                    {count}
                </span>
                selected
            </span>

            <span className="mx-1 h-5 w-px bg-border" />

            {isArchivedScope ? (
                <>
                    {can.restore && (
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => onAction('restore')}
                        >
                            <ArchiveRestore className="size-4" />
                            Restore
                        </Button>
                    )}
                    {can.forceDelete && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => onAction('delete')}
                        >
                            <Trash2 className="size-4" />
                            Delete
                        </Button>
                    )}
                </>
            ) : (
                <>
                    {can.manageStatus && (
                        <>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => onAction('activate')}
                            >
                                <CheckCircle2 className="size-4" />
                                Activate
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => onAction('deactivate')}
                            >
                                <PauseCircle className="size-4" />
                                Deactivate
                            </Button>
                        </>
                    )}
                    {can.assignRoles && roles.length > 0 && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="sm">
                                    <ShieldCheck className="size-4" />
                                    Assign role
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start">
                                <DropdownMenuLabel>
                                    Add a role to {count}{' '}
                                    {count === 1 ? 'user' : 'users'}
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                {roles.map((role) => (
                                    <DropdownMenuItem
                                        key={role.id}
                                        onSelect={() => onAssignRole(role.id)}
                                    >
                                        {role.label}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                    {can.delete && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-destructive hover:text-destructive"
                            onClick={() => onAction('archive')}
                        >
                            <Archive className="size-4" />
                            Archive
                        </Button>
                    )}
                </>
            )}

            <Button
                variant="ghost"
                size="sm"
                onClick={onClear}
                className="ml-auto text-muted-foreground"
            >
                <X className="size-4" />
                Clear
            </Button>
        </div>
    );
}
