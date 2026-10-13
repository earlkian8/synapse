import {
    Building2,
    Briefcase,
    LogIn,
    Smartphone,
    UsersRound,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { Link } from 'react-router';
import {
    DataTable,
    PageBody,
    PageHeader,
    StatTiles,
    TableCard,
} from '@/components/data-table';
import {
    LoadError,
    SkeletonRows,
    SkeletonTiles,
} from '@/components/page-states';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { EventPill } from '@/features/activity/components/event-pill';
import { organizationStatus } from '@/features/organizations/api';
import { OrganizationName } from '@/features/organizations/components/organization-name';
import { useCurrentAdmin } from '@/hooks/use-current-admin';
import { usePageTitle } from '@/hooks/use-page-title';
import { formatDateTime, formatNumber, formatRelative } from '@/lib/format';
import { useOverview } from './api';

/**
 * The first screen: the platform in five numbers, then what changed lately —
 * new organisations, ERP sign-ins and, for a super admin, admin activity.
 */
export function OverviewPage() {
    usePageTitle('Overview');

    const admin = useCurrentAdmin();
    const overview = useOverview();
    const stats = overview.data?.stats;

    return (
        <PageBody>
            <PageHeader
                title="Overview"
                description="SYNAPSE across every organisation, as of now."
            />

            {overview.isError && !overview.data ? (
                <LoadError
                    message={overview.error.message}
                    onRetry={() => void overview.refetch()}
                />
            ) : (
                <>
                    {stats ? (
                        <StatTiles
                            tiles={[
                                {
                                    key: 'organizations',
                                    label: 'Organisations',
                                    value: formatNumber(stats.organizations),
                                    hint: `${formatNumber(stats.in_setup)} in setup`,
                                    icon: Building2,
                                    accent: 'teal',
                                },
                                {
                                    key: 'accounts',
                                    label: 'ERP accounts',
                                    value: formatNumber(stats.accounts),
                                    hint: `${formatNumber(stats.unverified)} unverified`,
                                    icon: UsersRound,
                                    accent: 'indigo',
                                },
                                {
                                    key: 'active',
                                    label: 'Signed in, last 30 days',
                                    value: formatNumber(stats.active_30d),
                                    icon: LogIn,
                                    accent: 'emerald',
                                },
                                {
                                    key: 'employees',
                                    label: 'Employees',
                                    value: formatNumber(stats.employees),
                                    icon: Briefcase,
                                    accent: 'sky',
                                },
                                {
                                    key: 'mobile',
                                    label: 'App sessions open',
                                    value: formatNumber(stats.mobile_sessions),
                                    icon: Smartphone,
                                    accent: 'violet',
                                },
                            ]}
                        />
                    ) : (
                        <SkeletonTiles count={5} />
                    )}

                    <div className="grid gap-4 xl:grid-cols-2">
                        <TableCard
                            title="Newest organisations"
                            description={
                                stats
                                    ? `${formatNumber(stats.organizations_this_month)} joined this month`
                                    : undefined
                            }
                            actions={
                                <ViewAll to="/organisations?sort=created_at&direction=desc">
                                    All organisations
                                </ViewAll>
                            }
                        >
                            <DataTable>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Organisation</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">
                                            Members
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Joined
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {!overview.data && (
                                        <SkeletonRows columns={4} rows={5} />
                                    )}
                                    {overview.data?.newest_organizations
                                        .length === 0 && (
                                        <EmptyRow colSpan={4}>
                                            No organisation has registered yet.
                                        </EmptyRow>
                                    )}
                                    {overview.data?.newest_organizations.map(
                                        (organization) => (
                                            <TableRow key={organization.id}>
                                                <TableCell>
                                                    <Link
                                                        to={`/organisations?id=${organization.id}`}
                                                        className="rounded-md focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                                    >
                                                        <OrganizationName
                                                            organization={
                                                                organization
                                                            }
                                                            compact
                                                        />
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    {organizationStatus(
                                                        organization,
                                                    ) === 'setup' ? (
                                                        <StatusPill tone="amber">
                                                            In setup
                                                        </StatusPill>
                                                    ) : (
                                                        <StatusPill tone="emerald">
                                                            Ready
                                                        </StatusPill>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {formatNumber(
                                                        organization.members_count,
                                                    )}
                                                </TableCell>
                                                <TableCell
                                                    className="text-right text-muted-foreground"
                                                    title={formatDateTime(
                                                        organization.created_at,
                                                    )}
                                                >
                                                    {formatRelative(
                                                        organization.created_at,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                </TableBody>
                            </DataTable>
                        </TableCard>

                        <TableCard
                            title="Latest ERP sign-ins"
                            actions={
                                <ViewAll to="/accounts?sort=last_login_at&direction=desc">
                                    All accounts
                                </ViewAll>
                            }
                        >
                            <DataTable>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Account</TableHead>
                                        <TableHead className="text-right">
                                            Workspaces
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Signed in
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {!overview.data && (
                                        <SkeletonRows columns={3} rows={5} />
                                    )}
                                    {overview.data?.recent_sign_ins.length ===
                                        0 && (
                                        <EmptyRow colSpan={3}>
                                            Nobody has signed in to the ERP yet.
                                        </EmptyRow>
                                    )}
                                    {overview.data?.recent_sign_ins.map(
                                        (account) => (
                                            <TableRow key={account.id}>
                                                <TableCell className="max-w-0">
                                                    <Link
                                                        to={`/accounts?id=${account.id}`}
                                                        className="block min-w-0 rounded-md focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                                    >
                                                        <span className="block truncate font-medium">
                                                            {account.full_name}
                                                        </span>
                                                        <span className="block truncate text-xs text-muted-foreground">
                                                            {account.email}
                                                        </span>
                                                    </Link>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {account.memberships_count}
                                                </TableCell>
                                                <TableCell
                                                    className="text-right text-muted-foreground"
                                                    title={formatDateTime(
                                                        account.last_login_at,
                                                    )}
                                                >
                                                    {formatRelative(
                                                        account.last_login_at,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                </TableBody>
                            </DataTable>
                        </TableCard>
                    </div>

                    {admin.role === 'super_admin' && (
                        <TableCard
                            title="Admin activity"
                            actions={
                                <ViewAll to="/activity">Open the log</ViewAll>
                            }
                        >
                            <DataTable>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Admin</TableHead>
                                        <TableHead>Event</TableHead>
                                        <TableHead className="hidden md:table-cell">
                                            Description
                                        </TableHead>
                                        <TableHead className="text-right">
                                            When
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {!overview.data && (
                                        <SkeletonRows columns={4} rows={4} />
                                    )}
                                    {overview.data?.recent_activity?.map(
                                        (entry) => (
                                            <TableRow key={entry.id}>
                                                <TableCell className="max-w-48 truncate font-medium">
                                                    {entry.actor?.name ??
                                                        entry.actor?.email ??
                                                        'Server console'}
                                                </TableCell>
                                                <TableCell>
                                                    <EventPill
                                                        event={entry.event}
                                                        label={
                                                            entry.event_label
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="hidden max-w-sm truncate text-muted-foreground md:table-cell">
                                                    {entry.description}
                                                </TableCell>
                                                <TableCell
                                                    className="text-right text-muted-foreground"
                                                    title={formatDateTime(
                                                        entry.created_at,
                                                    )}
                                                >
                                                    {formatRelative(
                                                        entry.created_at,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ),
                                    )}
                                </TableBody>
                            </DataTable>
                        </TableCard>
                    )}
                </>
            )}
        </PageBody>
    );
}

function ViewAll({ to, children }: { to: string; children: ReactNode }) {
    return (
        <Button
            variant="ghost"
            size="sm"
            className="h-7 text-xs text-muted-foreground"
            asChild
        >
            <Link to={to}>{children}</Link>
        </Button>
    );
}

function EmptyRow({
    colSpan,
    children,
}: {
    colSpan: number;
    children: ReactNode;
}) {
    return (
        <TableRow className="hover:bg-transparent">
            <TableCell
                colSpan={colSpan}
                className="py-8 text-center text-sm text-muted-foreground"
            >
                {children}
            </TableCell>
        </TableRow>
    );
}
