import {
    Archive,
    Building2,
    CalendarPlus,
    CircleCheck,
    Hourglass,
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
import { useOrganizations } from './api';
import {
    OrganizationName,
    OrganizationStatusPill,
} from './components/organization-name';
import { OrganizationSheet } from './components/organization-sheet';

const STATUSES = [
    { value: 'all', label: 'All organisations' },
    { value: 'ready', label: 'Ready' },
    { value: 'setup', label: 'In setup' },
    { value: 'archived', label: 'Archived' },
];

/**
 * Every company on SYNAPSE, read-only: who is set up, how big each is, and
 * when anybody last did anything inside.
 */
export function OrganizationsPage() {
    usePageTitle('Organisations');

    const list = useListParams({ sort: 'created_at', direction: 'desc' });
    const { values } = list;
    const organizations = useOrganizations(values);
    const page = organizations.data;
    const stats = page?.stats;

    const sortProps = (column: string) => ({
        active: values.sort === column,
        direction: values.direction,
        onSort: () => list.toggleSort(column),
    });

    return (
        <PageBody>
            <PageHeader
                title="Organisations"
                description="Every company on SYNAPSE: how far each got with setup, how big it is, and when it was last used."
            />

            {stats ? (
                <StatTiles
                    tiles={[
                        {
                            key: 'total',
                            label: 'Organisations',
                            value: formatNumber(stats.total),
                            icon: Building2,
                            accent: 'teal',
                        },
                        {
                            key: 'ready',
                            label: 'Ready',
                            value: formatNumber(stats.ready),
                            icon: CircleCheck,
                            accent: 'emerald',
                        },
                        {
                            key: 'setup',
                            label: 'In setup',
                            value: formatNumber(stats.in_setup),
                            icon: Hourglass,
                            accent: 'amber',
                        },
                        {
                            key: 'month',
                            label: 'New this month',
                            value: formatNumber(stats.this_month),
                            icon: CalendarPlus,
                            accent: 'sky',
                        },
                        {
                            key: 'archived',
                            label: 'Archived',
                            value: formatNumber(stats.archived),
                            icon: Archive,
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
                    placeholder="Search name, email or slug"
                    label="Search organisations"
                />
                <FilterSelect
                    value={values.status}
                    onChange={list.setStatus}
                    options={STATUSES}
                    label="Status"
                />
            </ListToolbar>

            {organizations.isError && !page ? (
                <LoadError
                    message={organizations.error.message}
                    onRetry={() => void organizations.refetch()}
                />
            ) : (
                <>
                    <TableCard
                        className={
                            organizations.isPlaceholderData
                                ? 'opacity-70 transition-opacity'
                                : undefined
                        }
                    >
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <SortableHead {...sortProps('name')}>
                                        Organisation
                                    </SortableHead>
                                    <TableHead>Status</TableHead>
                                    <SortableHead
                                        {...sortProps('members_count')}
                                        align="right"
                                    >
                                        Members
                                    </SortableHead>
                                    <SortableHead
                                        {...sortProps('employees_count')}
                                        align="right"
                                    >
                                        Employees
                                    </SortableHead>
                                    <SortableHead
                                        {...sortProps('last_activity_at')}
                                        className="hidden lg:table-cell"
                                    >
                                        Last activity
                                    </SortableHead>
                                    <SortableHead
                                        {...sortProps('created_at')}
                                        className="hidden md:table-cell"
                                    >
                                        Joined
                                    </SortableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {!page && <SkeletonRows columns={6} />}
                                {page?.data.length === 0 && (
                                    <EmptyTableRow
                                        colSpan={6}
                                        icon={Building2}
                                        title={
                                            list.filtered
                                                ? 'No organisation matches'
                                                : 'No organisations yet'
                                        }
                                        description={
                                            list.filtered
                                                ? 'Try another search, or reset the filters.'
                                                : 'Companies appear here as soon as somebody registers one in the ERP.'
                                        }
                                    />
                                )}
                                {page?.data.map((organization) => (
                                    <TableRow
                                        key={organization.id}
                                        {...rowOpens(() =>
                                            list.open(organization.id),
                                        )}
                                    >
                                        <TableCell className="max-w-72">
                                            <OrganizationName
                                                organization={organization}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <OrganizationStatusPill
                                                organization={organization}
                                            />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatNumber(
                                                organization.members_count,
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatNumber(
                                                organization.employees_count,
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className="hidden text-muted-foreground lg:table-cell"
                                            title={formatDateTime(
                                                organization.last_activity_at,
                                            )}
                                        >
                                            {formatRelative(
                                                organization.last_activity_at,
                                                'Nothing yet',
                                            )}
                                        </TableCell>
                                        <TableCell className="hidden text-muted-foreground md:table-cell">
                                            {formatDate(
                                                organization.created_at,
                                            )}
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

            <OrganizationSheet
                id={list.openId}
                onClose={() => list.open(null)}
            />
        </PageBody>
    );
}
