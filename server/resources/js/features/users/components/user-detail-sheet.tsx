import {
    AtSign,
    BadgeCheck,
    CalendarPlus,
    Clock,
    Hash,
    KeyRound,
    MailCheck,
    Phone,
    ShieldCheck,
    ShieldX,
    Users as UsersIcon,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { ManagedUser } from '../types';
import { UserAvatar } from './user-avatar';
import { UserStatusBadge } from './user-status-badge';

type Props = {
    user: ManagedUser | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onEdit: (user: ManagedUser) => void;
    onResendVerification: (user: ManagedUser) => void;
};

export function UserDetailSheet({
    user,
    open,
    onOpenChange,
    onEdit,
    onResendVerification,
}: Props) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="sr-only">
                    <SheetTitle>User profile</SheetTitle>
                </SheetHeader>

                {user && (
                    <>
                        <div className="border-b border-border bg-muted/30 px-6 py-6">
                            <div className="flex items-start gap-4">
                                <UserAvatar
                                    user={user}
                                    className="size-14 text-base"
                                />
                                <div className="min-w-0 flex-1">
                                    <h2 className="truncate text-lg font-semibold">
                                        {user.full_name}
                                    </h2>
                                    <p className="truncate text-sm text-muted-foreground">
                                        {user.email}
                                    </p>
                                    <div className="mt-2">
                                        <UserStatusBadge status={user.status} />
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div className="space-y-6 px-6 py-6">
                            <Group title="Contact & identity">
                                <Row
                                    icon={AtSign}
                                    label="Email"
                                    value={user.email}
                                />
                                <Row
                                    icon={Phone}
                                    label="Phone"
                                    value={user.phone_number}
                                />
                                <Row
                                    icon={Hash}
                                    label="Employee ID"
                                    value={user.employee_id}
                                />
                            </Group>

                            <Group title="Roles">
                                {user.roles.length > 0 ? (
                                    <div className="flex flex-wrap gap-1.5 px-2 py-1">
                                        {user.roles.map((role) => (
                                            <span
                                                key={role.id}
                                                className="inline-flex items-center gap-1.5 rounded-full border border-[#0ABFBF]/30 bg-[#0ABFBF]/10 px-2.5 py-0.5 text-xs font-medium text-[#0ABFBF]"
                                            >
                                                <UsersIcon className="size-3" />
                                                {role.label}
                                            </span>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="px-2 py-1 text-sm text-muted-foreground">
                                        No roles assigned
                                    </p>
                                )}
                            </Group>

                            <Group title="Security">
                                <Row
                                    icon={
                                        user.email_verified
                                            ? ShieldCheck
                                            : ShieldX
                                    }
                                    label="Email verified"
                                    value={
                                        user.email_verified
                                            ? 'Verified'
                                            : 'Not verified'
                                    }
                                    tone={
                                        user.email_verified
                                            ? 'positive'
                                            : 'warning'
                                    }
                                />
                                <Row
                                    icon={BadgeCheck}
                                    label="Two-factor"
                                    value={
                                        user.two_factor_enabled
                                            ? 'Enabled'
                                            : 'Disabled'
                                    }
                                    tone={
                                        user.two_factor_enabled
                                            ? 'positive'
                                            : undefined
                                    }
                                />
                                <Row
                                    icon={KeyRound}
                                    label="Password"
                                    value={
                                        user.has_password
                                            ? 'Set'
                                            : 'Not set (invited)'
                                    }
                                />
                                {!user.email_verified &&
                                    user.status !== 'archived' && (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            className="mt-1 w-full"
                                            onClick={() =>
                                                onResendVerification(user)
                                            }
                                        >
                                            <MailCheck className="size-4" />
                                            Resend verification email
                                        </Button>
                                    )}
                            </Group>

                            <Group title="Activity">
                                <Row
                                    icon={Clock}
                                    label="Last login"
                                    value={
                                        user.last_login_human ??
                                        'Never signed in'
                                    }
                                />
                                <Row
                                    icon={CalendarPlus}
                                    label="Created"
                                    value={user.created_human}
                                />
                            </Group>
                        </div>

                        {user.status !== 'archived' && (
                            <div className="sticky bottom-0 border-t border-border bg-background px-6 py-4">
                                <Button
                                    className="w-full"
                                    onClick={() => onEdit(user)}
                                >
                                    Edit user
                                </Button>
                            </div>
                        )}
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}

function Group({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <h3 className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                {title}
            </h3>
            <div className="space-y-1">{children}</div>
        </div>
    );
}

function Row({
    icon: Icon,
    label,
    value,
    tone,
}: {
    icon: ComponentType<{ className?: string }>;
    label: string;
    value?: string | null;
    tone?: 'positive' | 'warning';
}) {
    const toneClass =
        tone === 'positive'
            ? 'text-emerald-600 dark:text-emerald-400'
            : tone === 'warning'
              ? 'text-amber-600 dark:text-amber-400'
              : 'text-foreground';

    return (
        <div className="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-muted/40">
            <span className="flex items-center gap-2 text-sm text-muted-foreground">
                <Icon className="size-4" />
                {label}
            </span>
            <span className={`truncate text-sm font-medium ${toneClass}`}>
                {value || '—'}
            </span>
        </div>
    );
}
