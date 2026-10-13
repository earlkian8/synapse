import {
    Archive,
    AtSign,
    BadgeCheck,
    CalendarPlus,
    Clock,
    KeyRound,
    MailCheck,
    ShieldCheck,
    Smartphone,
    Star,
} from 'lucide-react';
import { Link } from 'react-router';
import { DetailGroup, DetailRow } from '@/components/detail-list';
import { LoadError } from '@/components/page-states';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { formatDate, formatDateTime, formatRelative } from '@/lib/format';
import { useAccount } from '../api';
import { AccountStatusPill } from './account-status-pill';

/** One ERP account, read-only: how it signs in and where it belongs. */
export function AccountSheet({
    id,
    onClose,
}: {
    id: number | null;
    onClose: () => void;
}) {
    const account = useAccount(id);
    const record = account.data?.data;

    return (
        <Sheet open={id !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                side="right"
                className="w-full gap-0 overflow-y-auto p-0 sm:max-w-md"
            >
                <SheetHeader className="sr-only">
                    <SheetTitle>{record?.full_name ?? 'Account'}</SheetTitle>
                    <SheetDescription>
                        How this account signs in, and the organisations it
                        belongs to.
                    </SheetDescription>
                </SheetHeader>

                {account.isError && (
                    <div className="p-6">
                        <LoadError
                            message={account.error.message}
                            onRetry={() => void account.refetch()}
                        />
                    </div>
                )}

                {!record && !account.isError && (
                    <div className="space-y-4 p-6">
                        <Skeleton className="h-14 w-full rounded-xl" />
                        <Skeleton className="h-40 w-full rounded-xl" />
                    </div>
                )}

                {record && (
                    <>
                        <div className="border-b border-border bg-muted/30 px-6 py-6">
                            <h2 className="truncate text-lg font-semibold">
                                {record.full_name}
                            </h2>
                            <p className="truncate text-sm text-muted-foreground">
                                {record.email}
                            </p>
                            <div className="mt-2">
                                <AccountStatusPill account={record} />
                            </div>
                        </div>

                        <div className="space-y-6 px-6 py-6">
                            <DetailGroup title="Workspaces">
                                {record.memberships.length === 0 ? (
                                    <p className="px-2 py-1 text-sm text-muted-foreground">
                                        Registered, but hasn't joined an
                                        organisation yet.
                                    </p>
                                ) : (
                                    record.memberships.map((membership) => (
                                        <Link
                                            key={membership.id}
                                            to={`/organisations?id=${membership.id}`}
                                            className="flex items-center justify-between gap-3 rounded-lg px-2 py-2 hover:bg-muted/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                        >
                                            <span className="min-w-0">
                                                <span className="flex items-center gap-1.5 truncate text-sm font-medium">
                                                    {membership.name}
                                                    {membership.is_default && (
                                                        <Star
                                                            className="size-3 shrink-0 fill-amber-400 text-amber-400"
                                                            aria-label="Opens by default"
                                                        />
                                                    )}
                                                    {membership.archived && (
                                                        <Archive
                                                            className="size-3 shrink-0 text-muted-foreground"
                                                            aria-label="Archived"
                                                        />
                                                    )}
                                                </span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {membership.roles.join(
                                                        ', ',
                                                    ) || 'No role'}
                                                </span>
                                            </span>
                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                Joined{' '}
                                                {formatDate(
                                                    membership.joined_at,
                                                )}
                                            </span>
                                        </Link>
                                    ))
                                )}
                            </DetailGroup>

                            <DetailGroup title="Security">
                                <DetailRow
                                    icon={
                                        record.email_verified_at
                                            ? MailCheck
                                            : AtSign
                                    }
                                    label="Email"
                                    value={
                                        record.email_verified_at
                                            ? 'Verified'
                                            : 'Not verified'
                                    }
                                    tone={
                                        record.email_verified_at
                                            ? 'positive'
                                            : 'warning'
                                    }
                                />
                                <DetailRow
                                    icon={
                                        record.two_factor_enabled
                                            ? ShieldCheck
                                            : BadgeCheck
                                    }
                                    label="Two-factor"
                                    value={
                                        record.two_factor_enabled ? 'On' : 'Off'
                                    }
                                    tone={
                                        record.two_factor_enabled
                                            ? 'positive'
                                            : undefined
                                    }
                                />
                                <DetailRow
                                    icon={KeyRound}
                                    label="Password changed"
                                    value={formatRelative(
                                        record.password_changed_at,
                                        'Never',
                                    )}
                                    title={formatDateTime(
                                        record.password_changed_at,
                                    )}
                                />
                                <DetailRow
                                    icon={Smartphone}
                                    label="App sessions open"
                                    value={
                                        record.mobile_sessions === 0
                                            ? 'None'
                                            : `${record.mobile_sessions}, last used ${formatRelative(record.mobile_last_used_at, 'never')}`
                                    }
                                />
                            </DetailGroup>

                            <DetailGroup title="Activity">
                                <DetailRow
                                    icon={Clock}
                                    label="Last sign-in"
                                    value={formatRelative(
                                        record.last_login_at,
                                        'Never',
                                    )}
                                    title={formatDateTime(record.last_login_at)}
                                />
                                <DetailRow
                                    icon={CalendarPlus}
                                    label="Created"
                                    value={formatDate(record.created_at)}
                                    title={formatDateTime(record.created_at)}
                                />
                                {record.archived_at && (
                                    <DetailRow
                                        icon={Archive}
                                        label="Archived"
                                        value={formatDate(record.archived_at)}
                                        tone="warning"
                                    />
                                )}
                            </DetailGroup>
                        </div>
                    </>
                )}
            </SheetContent>
        </Sheet>
    );
}
