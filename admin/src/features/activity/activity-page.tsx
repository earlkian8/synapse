import { CalendarClock, ScrollText, ShieldX, UsersRound } from 'lucide-react';
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
import { formatDateTime, formatNumber, formatRelative } from '@/lib/format';
import { useActivity } from './api';
import { ActivityDialog } from './components/activity-dialog';
import { EventPill } from './components/event-pill';

/**
 * What admins did in the portal, and every attempt to get in. Append-only:
 * there is nothing here, or in the API, that edits or deletes an entry.
 */
export function ActivityPage() {
    usePageTitle('Activity log');

    const list = useListParams({
        sort: 'created_at',
        direction: 'desc',
        extra: { event: 'all', admin_id: 'all' },
    });
    const { values, extras } = list;
    const activity = useActivity({
        ...values,
        event: extras.event,
        admin_id: extras.admin_id,
    });
    const page = activity.data;
    const stats = page?.stats;
    const open = page?.data.find((entry) => entry.id === list.openId) ?? null;

    const sortProps = (column: string) => ({
        active: values.sort === column,
        direction: values.direction,
        onSort: () => list.toggleSort(column),
    });

    return (
        <PageBody>
            <PageHeader
                title="Activity log"
                description="Every sign-in, failed attempt and change made in this portal, newest first."
            />

            {stats ? (
                <StatTiles
                    tiles={[
                        {
                            key: 'total',
                            label: 'Entries',
                            value: formatNumber(stats.total),
                            icon: ScrollText,
                            accent: 'slate',
                        },
                        {
                            key: 'today',
                            label: 'Today',
                            value: formatNumber(stats.today),
                            icon: CalendarClock,
                            accent: 'sky',
                        },
                        {
                            key: 'failed',
                            label: 'Failed sign-ins, 7 days',
                            value: formatNumber(stats.failed_sign_ins_7d),
                            icon: ShieldX,
                            accent: 'rose',
                            valueClassName:
                                stats.failed_sign_ins_7d > 0
                                    ? 'text-rose-600 dark:text-rose-400'
                                    : undefined,
                        },
                        {
                            key: 'admins',
                            label: 'Admins in, 7 days',
                            value: formatNumber(stats.admins_active_7d),
                            icon: UsersRound,
                            accent: 'emerald',
                        },
                    ]}
                />
            ) : (
                <SkeletonTiles count={4} />
            )}

            <ListToolbar filtered={list.filtered} onReset={list.reset}>
                <SearchInput
                    value={values.search}
                    onSearch={list.setSearch}
                    placeholder="Search description, admin or IP"
                    label="Search the log"
                />
                <FilterSelect
                    value={extras.event}
                    onChange={(value) => list.setExtra('event', value)}
                    options={[
                        { value: 'all', label: 'All events' },
                        ...(page?.events ?? []),
                    ]}
                    label="Event"
                    className="w-48"
                />
                <FilterSelect
                    value={extras.admin_id}
                    onChange={(value) => list.setExtra('admin_id', value)}
                    options={[
                        { value: 'all', label: 'All admins' },
                        ...(page?.admins ?? []).map((admin) => ({
                            value: String(admin.id),
                            label: admin.name,
                        })),
                    ]}
                    label="Admin"
                    className="w-44"
                />
            </ListToolbar>

            {activity.isError && !page ? (
                <LoadError
                    message={activity.error.message}
                    onRetry={() => void activity.refetch()}
                />
            ) : (
                <>
                    <TableCard
                        className={
                            activity.isPlaceholderData
                                ? 'opacity-70 transition-opacity'
                                : undefined
                        }
                    >
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Admin</TableHead>
                                    <SortableHead {...sortProps('event')}>
                                        Event
                                    </SortableHead>
                                    <TableHead className="hidden md:table-cell">
                                        Description
                                    </TableHead>
                                    <TableHead className="hidden xl:table-cell">
                                        Target
                                    </TableHead>
                                    <TableHead className="hidden lg:table-cell">
                                        IP address
                                    </TableHead>
                                    <SortableHead
                                        {...sortProps('created_at')}
                                        align="right"
                                    >
                                        When
                                    </SortableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {!page && <SkeletonRows columns={6} />}
                                {page?.data.length === 0 && (
                                    <EmptyTableRow
                                        colSpan={6}
                                        icon={ScrollText}
                                        title={
                                            list.filtered
                                                ? 'Nothing matches'
                                                : 'No activity yet'
                                        }
                                        description={
                                            list.filtered
                                                ? 'Try another search, or reset the filters.'
                                                : 'Sign-ins and changes appear here as they happen.'
                                        }
                                    />
                                )}
                                {page?.data.map((entry) => (
                                    <TableRow
                                        key={entry.id}
                                        {...rowOpens(() => list.open(entry.id))}
                                    >
                                        <TableCell className="max-w-56">
                                            <span className="block truncate font-medium">
                                                {entry.actor?.name ??
                                                    entry.actor?.email ??
                                                    'Server console'}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {entry.actor?.name
                                                    ? entry.actor.email
                                                    : entry.actor
                                                      ? 'No admin has this email'
                                                      : 'A command on the server'}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <EventPill
                                                event={entry.event}
                                                label={entry.event_label}
                                            />
                                        </TableCell>
                                        <TableCell className="hidden max-w-xs md:table-cell">
                                            <span className="block truncate text-sm">
                                                {entry.description}
                                            </span>
                                        </TableCell>
                                        <TableCell className="hidden max-w-56 truncate text-sm text-muted-foreground xl:table-cell">
                                            {entry.subject_label ?? '—'}
                                        </TableCell>
                                        <TableCell className="hidden font-mono text-xs text-muted-foreground lg:table-cell">
                                            {entry.ip_address ?? '—'}
                                        </TableCell>
                                        <TableCell
                                            className="text-right text-sm text-muted-foreground"
                                            title={formatDateTime(
                                                entry.created_at,
                                            )}
                                        >
                                            {formatRelative(entry.created_at)}
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

            <ActivityDialog
                entry={open}
                open={list.openId !== null && open !== null}
                onClose={() => list.open(null)}
            />
        </PageBody>
    );
}
