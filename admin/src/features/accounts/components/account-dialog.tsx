import {
    Archive,
    CalendarPlus,
    Clock,
    History,
    KeyRound,
    MailCheck,
    ShieldCheck,
    Smartphone,
    Star,
} from 'lucide-react';
import { Link } from 'react-router';
import { LoadError } from '@/components/page-states';
import {
    Fact,
    FactGrid,
    RecordDialog,
    RecordHeading,
    RecordList,
    RecordSection,
    RecordSkeleton,
} from '@/components/record-dialog';
import { formatDate, formatDateTime, formatRelative } from '@/lib/format';
import { useAccount } from '../api';
import { AccountStatusPill } from './account-status-pill';

/** One ERP account, read-only: how it signs in and where it belongs. */
export function AccountDialog({
    id,
    onClose,
}: {
    id: number | null;
    onClose: () => void;
}) {
    const account = useAccount(id);
    const record = account.data?.data;

    return (
        <RecordDialog
            open={id !== null}
            onOpenChange={(open) => !open && onClose()}
            title={record?.full_name ?? 'Account'}
            description="How this account signs in, and the organisations it belongs to."
            header={
                record ? (
                    <RecordHeading
                        title={record.full_name}
                        subtitle={record.email}
                        badges={<AccountStatusPill account={record} />}
                    />
                ) : undefined
            }
        >
            {account.isError && (
                <LoadError
                    message={account.error.message}
                    onRetry={() => void account.refetch()}
                />
            )}

            {!record && !account.isError && <RecordSkeleton />}

            {record && (
                <div className="space-y-4">
                    <RecordSection title="Sign-in and security">
                        <FactGrid>
                            <Fact
                                icon={MailCheck}
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
                            <Fact
                                icon={ShieldCheck}
                                label="Two-factor"
                                value={record.two_factor_enabled ? 'On' : 'Off'}
                                tone={
                                    record.two_factor_enabled
                                        ? 'positive'
                                        : undefined
                                }
                            />
                            <Fact
                                icon={Clock}
                                label="Last sign-in"
                                value={formatRelative(
                                    record.last_login_at,
                                    'Never',
                                )}
                                title={formatDateTime(record.last_login_at)}
                            />
                            <Fact
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
                            <Fact
                                icon={Smartphone}
                                label="App sessions"
                                value={
                                    record.mobile_sessions === 0
                                        ? 'None'
                                        : String(record.mobile_sessions)
                                }
                            />
                            <Fact
                                icon={History}
                                label="App last used"
                                value={formatRelative(
                                    record.mobile_last_used_at,
                                    'Never',
                                )}
                                title={formatDateTime(
                                    record.mobile_last_used_at,
                                )}
                            />
                            <Fact
                                icon={CalendarPlus}
                                label="Created"
                                value={formatDate(record.created_at)}
                                title={formatDateTime(record.created_at)}
                            />
                            <Fact
                                icon={Archive}
                                label="Archived"
                                value={
                                    record.archived_at
                                        ? formatDate(record.archived_at)
                                        : 'No'
                                }
                                tone={
                                    record.archived_at ? 'warning' : undefined
                                }
                            />
                        </FactGrid>
                    </RecordSection>

                    <RecordSection
                        title="Workspaces"
                        aside={
                            record.memberships.length > 0
                                ? String(record.memberships.length)
                                : undefined
                        }
                    >
                        <RecordList empty="Registered, but hasn't joined an organisation yet.">
                            {record.memberships.map((membership) => (
                                <li key={membership.id}>
                                    <Link
                                        to={`/organisations?id=${membership.id}`}
                                        className="flex items-center gap-3 px-3 py-2 transition-colors hover:bg-muted/40 focus-visible:bg-muted/50 focus-visible:outline-none"
                                    >
                                        <span className="min-w-0 flex-1">
                                            <span className="flex items-center gap-1.5 text-sm font-medium">
                                                <span className="truncate">
                                                    {membership.name}
                                                </span>
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
                                                {membership.roles.join(', ') ||
                                                    'No role'}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            Joined{' '}
                                            {formatDate(membership.joined_at)}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </RecordList>
                    </RecordSection>
                </div>
            )}
        </RecordDialog>
    );
}
