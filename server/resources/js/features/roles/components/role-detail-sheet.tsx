import { KeyRound, ShieldCheck, Users } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { ManagedRole, PermissionGroup } from '../types';
import { PermissionMatrix } from './permission-matrix';
import { RoleBadge } from './role-badge';

type Props = {
    role: ManagedRole | null;
    groups: PermissionGroup[];
    open: boolean;
    canEdit: boolean;
    onOpenChange: (open: boolean) => void;
    onEdit: (role: ManagedRole) => void;
};

export function RoleDetailSheet({
    role,
    groups,
    open,
    canEdit,
    onOpenChange,
    onEdit,
}: Props) {
    const editable = Boolean(role) && canEdit && !role?.is_super_admin;

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-xl"
            >
                <SheetHeader className="sr-only">
                    <SheetTitle>Role permissions</SheetTitle>
                </SheetHeader>

                {role && (
                    <>
                        <div className="border-b border-border bg-muted/30 px-6 py-6">
                            <div className="flex items-start gap-4">
                                <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-[#0F2044] text-white">
                                    <ShieldCheck className="size-6" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <h2 className="truncate text-lg font-semibold">
                                        {role.label}
                                    </h2>
                                    <p className="truncate font-mono text-xs text-muted-foreground">
                                        {role.name}
                                    </p>
                                    <div className="mt-2">
                                        <RoleBadge role={role} />
                                    </div>
                                </div>
                            </div>

                            {role.description && (
                                <p className="mt-4 text-sm text-muted-foreground">
                                    {role.description}
                                </p>
                            )}

                            <div className="mt-4 flex flex-wrap gap-2">
                                <Stat
                                    icon={KeyRound}
                                    label="Permissions"
                                    value={
                                        role.is_super_admin
                                            ? 'All'
                                            : role.permissions_count
                                    }
                                />
                                <Stat
                                    icon={Users}
                                    label="Members"
                                    value={role.users_count}
                                />
                            </div>
                        </div>

                        <div className="px-6 py-6">
                            {role.is_super_admin && (
                                <div className="mb-4 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-3 text-sm text-indigo-700 dark:border-indigo-500/20 dark:bg-indigo-500/10 dark:text-indigo-300">
                                    The Super Admin role bypasses every
                                    permission check and always has full access.
                                </div>
                            )}

                            <PermissionMatrix
                                groups={groups}
                                value={role.permissions}
                                grantAll={role.is_super_admin}
                                readOnly
                            />
                        </div>

                        {editable && (
                            <div className="sticky bottom-0 border-t border-border bg-background px-6 py-4">
                                <Button
                                    className="w-full"
                                    onClick={() => onEdit(role)}
                                >
                                    Edit role
                                </Button>
                            </div>
                        )}
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

function Stat({
    icon: Icon,
    label,
    value,
}: {
    icon: typeof KeyRound;
    label: string;
    value: string | number;
}) {
    return (
        <span className="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-3 py-1.5 text-sm">
            <Icon className="size-4 text-muted-foreground" />
            <span className="font-semibold tabular-nums">{value}</span>
            <span className="text-muted-foreground">{label}</span>
        </span>
    );
}
