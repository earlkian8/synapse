import {
    Ban,
    DoorClosed,
    LogIn,
    MailWarning,
    ShieldCheck,
    UsersRound,
} from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    FilterSelect,
    ListToolbar,
    PageBody,
    PageHeader,
    SearchInput,
    SortableHead,
    StatTiles,
    TableCard,
    TablePagination,
    rowOpens,
} from '@/components/data-table';
import {
    LoadError,
    SkeletonRows,
    SkeletonTiles,
} from '@/components/page-states';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useListParams } from '@/hooks/use-list-params';
import { usePageTitle } from '@/hooks/use-page-title';
import {
    formatDate,
    formatDateTime,
    formatNumber,
    formatRelative,
} from '@/lib/format';
import { useAccounts } from './api';
import { AccountDialog } from './components/account-dialog';
import { AccountStatusPill } from './components/account-status-pill';

const STATUSES = [
    { value: 'all', label: 'All accounts' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'unverified', label: 'Unverified' },
    { value: 'no_workspace', label: 'In no workspace' },
    { value: 'archived', label: 'Archived' },
];

/**
 * Every sign-in account of the web ERP and the app, across organisations, as
 * an identity: how it signs in, not the HR record behind it. Read-only — each
 * organisation's HR manages its own people.
 */
export function AccountsPage() {
    usePageTitle('Accounts');

    const list = useListParams({ sort: 'created_at', direction: 'desc' });
    const { values } = list;
    const accounts = useAccounts(values);
    const page = accounts.data;
    const stats = page?.stats;

    const sortProps = (column: string) => ({
        active: values.sort === column,
        direction: values.direction,
        onSort: () => list.toggleSort(column),
    });

    return (
        <PageBody>
            <PageHeader
                title="Accounts"
                description="Everyone who signs in to the ERP or the app, whichever organisations they belong to."
            />

            {stats ? (
                <StatTiles
                    tiles={[
                        {
                            key: 'total',
                            label: 'Accounts',
                            value: formatNumber(stats.total),
                            icon: UsersRound,
                            accent: 'indigo',
                        },
                        {
                            key: 'active',
                            label: 'Signed in, 30 days',
                            value: formatNumber(stats.active_30d),
                            icon: LogIn,
                            accent: 'emerald',
                        },
                        {
                            key: 'unverified',
                            label: 'Unverified email',
                            value: formatNumber(stats.unverified),
                            icon: MailWarning,
                            accent: 'sky',
                        },
                        {
                            key: 'inactive',
                            label: 'Inactive',
                            value: formatNumber(stats.inactive),
                            icon: Ban,
                            accent: 'amber',
                        },
                        {
                            key: 'none',
                            label: 'In no workspace',
                            value: formatNumber(stats.no_workspace),
                            icon: DoorClosed,
                            accent: 'slate',
                        },
                    ]}
                />
            ) : (
                <SkeletonTiles count={5} />
            )}

            <ListToolbar filtered={list.filtered} onReset={list.reset}>
                <SearchInput
                    value={values.search}
                    onSearch={list.setSearch}
                    placeholder="Search name or email"
                    label="Search accounts"
                />
                <FilterSelect
                    value={values.status}
                    onChange={list.setStatus}
                    options={STATUSES}
                    label="Status"
                    className="w-44"
                />
            </ListToolbar>

            {accounts.isError && !page ? (
                <LoadError
                    message={accounts.error.message}
                    onRetry={() => void accounts.refetch()}
                />
            ) : (
                <>
                    <TableCard
                        className={
                            accounts.isPlaceholderData
                                ? 'opacity-70 transition-opacity'
                                : undefined
                        }
                    >
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <SortableHead {...sortProps('name')}>
                                        Account
                                    </SortableHead>
                                    <TableHead>Status</TableHead>
                                    <SortableHead
                                        {...sortProps('memberships_count')}
                                        align="right"
                                    >
                                        Workspaces
                                    </SortableHead>
                                    <TableHead className="hidden lg:table-cell">
                                        Two-factor
                                    </TableHead>
                                    <SortableHead
                                        {...sortProps('last_login_at')}
                                        className="hidden md:table-cell"
                                    >
                                        Last sign-in
                                    </SortableHead>
                                    <SortableHead
                                        {...sortProps('created_at')}
                                        className="hidden xl:table-cell"
                                    >
                                        Created
                                    </SortableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {!page && <SkeletonRows columns={6} />}
                                {page?.data.length === 0 && (
                                    <EmptyTableRow
                                        colSpan={6}
                                        icon={UsersRound}
                                        title={
                                            list.filtered
                                                ? 'No account matches'
                                                : 'No accounts yet'
                                        }
                                        description={
                                            list.filtered
                                                ? 'Try another search, or reset the filters.'
                                                : 'Accounts appear here as people register in the ERP or the app.'
                                        }
                                    />
                                )}
                                {page?.data.map((account) => (
                                    <TableRow
                                        key={account.id}
                                        {...rowOpens(() =>
                                            list.open(account.id),
                                        )}
                                    >
                                        <TableCell className="max-w-72">
                                            <span className="block truncate font-medium">
                                                {account.full_name}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {account.email}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <AccountStatusPill
                                                account={account}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {account.memberships_count}
                                        </TableCell>
                                        <TableCell className="hidden lg:table-cell">
                                            {account.two_factor_enabled ? (
                                                <span className="inline-flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400">
                                                    <ShieldCheck className="size-4" />
                                                    On
                                                </span>
                                            ) : (
                                                <span className="text-sm text-muted-foreground">
                                                    Off
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className="hidden text-muted-foreground md:table-cell"
                                            title={formatDateTime(
                                                account.last_login_at,
                                            )}
                                        >
                                            {formatRelative(
                                                account.last_login_at,
                                                'Never',
                                            )}
                                        </TableCell>
                                        <TableCell className="hidden text-muted-foreground xl:table-cell">
                                            {formatDate(account.created_at)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </DataTable>
                    </TableCard>

                    {page && (
                        <TablePagination
                            meta={page.meta}
                            perPage={page.meta.per_page}
                            onPage={list.setPage}
                            onPerPage={list.setPerPage}
                        />
                    )}
                </>
            )}

            <AccountDialog id={list.openId} onClose={() => list.open(null)} />
        </PageBody>
    );
}
