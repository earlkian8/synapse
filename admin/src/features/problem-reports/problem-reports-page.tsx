import {
    CircleCheck,
    Inbox,
    MessageSquareReply,
    MessageSquareWarning,
    OctagonAlert,
    Paperclip,
    Search,
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
import { formatDateTime, formatNumber, formatRelative } from '@/lib/format';
import {
    CATEGORY_OPTIONS,
    IMPACT_OPTIONS,
    PLATFORM_OPTIONS,
    STATUS_OPTIONS,
    useProblemReports,
} from './api';
import { ImpactMeter, ReportStatusPill } from './components/report-pills';
import { ReportDialog } from './components/report-dialog';

/**
 * Problems people reported from the ERP and the app (ADR 0096), from every
 * organisation — the open ones first, since each is somebody waiting on an
 * answer. Every admin follows them up.
 */
export function ProblemReportsPage() {
    usePageTitle('Problem reports');

    const list = useListParams({
        sort: 'created_at',
        direction: 'desc',
        status: 'open',
        extra: { category: 'all', impact: 'all', platform: 'all' },
    });
    const { values, extras } = list;
    const reports = useProblemReports({
        ...values,
        category: extras.category,
        impact: extras.impact,
        platform: extras.platform,
    });
    const page = reports.data;
    const stats = page?.stats;

    const sortProps = (column: string) => ({
        active: values.sort === column,
        direction: values.direction,
        onSort: () => list.toggleSort(column),
    });

    return (
        <PageBody>
            <PageHeader
                title="Problem reports"
                description="What people reported from the ERP and the app. Each open one is somebody waiting on an answer."
            />

            {stats ? (
                <StatTiles
                    tiles={[
                        {
                            key: 'new',
                            label: 'New',
                            value: formatNumber(stats.new),
                            icon: Inbox,
                            accent: 'sky',
                        },
                        {
                            key: 'investigating',
                            label: 'Investigating',
                            value: formatNumber(stats.investigating),
                            icon: Search,
                            accent: 'amber',
                        },
                        {
                            key: 'blocked',
                            label: 'Open and blocking work',
                            value: formatNumber(stats.blocked),
                            icon: OctagonAlert,
                            accent: 'rose',
                        },
                        {
                            key: 'resolved',
                            label: 'Resolved, 30 days',
                            value: formatNumber(stats.resolved_30d),
                            icon: CircleCheck,
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
                    placeholder="Search reports"
                    label="Search problem reports"
                />
                <FilterSelect
                    value={values.status}
                    onChange={list.setStatus}
                    options={STATUS_OPTIONS}
                    label="Status"
                    className="w-36"
                />
                <FilterSelect
                    value={extras.impact}
                    onChange={(value) => list.setExtra('impact', value)}
                    options={IMPACT_OPTIONS}
                    label="Impact"
                    className="w-40"
                />
                <FilterSelect
                    value={extras.category}
                    onChange={(value) => list.setExtra('category', value)}
                    options={CATEGORY_OPTIONS}
                    label="Kind"
                    className="w-40"
                />
                <FilterSelect
                    value={extras.platform}
                    onChange={(value) => list.setExtra('platform', value)}
                    options={PLATFORM_OPTIONS}
                    label="Platform"
                    className="w-36"
                />
            </ListToolbar>

            {reports.isError && !page ? (
                <LoadError
                    message={reports.error.message}
                    onRetry={() => void reports.refetch()}
                />
            ) : (
                <>
                    <TableCard
                        className={
                            reports.isPlaceholderData
                                ? 'opacity-70 transition-opacity'
                                : undefined
                        }
                    >
                        <DataTable>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Report</TableHead>
                                    <TableHead className="hidden lg:table-cell">
                                        Sent by
                                    </TableHead>
                                    <SortableHead
                                        {...sortProps('impact')}
                                        className="hidden md:table-cell"
                                    >
                                        Impact
                                    </SortableHead>
                                    <TableHead>Status</TableHead>
                                    <SortableHead
                                        {...sortProps('created_at')}
                                        align="right"
                                        className="hidden sm:table-cell"
                                    >
                                        Sent
                                    </SortableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {!page && <SkeletonRows columns={5} />}
                                {page?.data.length === 0 && (
                                    <EmptyTableRow
                                        colSpan={5}
                                        icon={MessageSquareWarning}
                                        title={
                                            list.filtered
                                                ? 'No report matches'
                                                : 'Nothing open'
                                        }
                                        description={
                                            list.filtered
                                                ? 'Try another search, or reset the filters.'
                                                : 'Every report has been followed up. New ones from the ERP and the app appear here.'
                                        }
                                    />
                                )}
                                {page?.data.map((report) => (
                                    <TableRow
                                        key={report.id}
                                        {...rowOpens(() =>
                                            list.open(report.id),
                                        )}
                                    >
                                        <TableCell className="max-w-0 min-w-56">
                                            <span className="block truncate font-medium">
                                                {report.subject}
                                            </span>
                                            <span className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                                                <span className="shrink-0 tabular-nums">
                                                    {report.reference}
                                                </span>
                                                <span className="truncate">
                                                    {report.category_label} ·{' '}
                                                    {report.platform_label}
                                                </span>
                                                {report.attachments_count >
                                                    0 && (
                                                    <span
                                                        className="inline-flex shrink-0 items-center gap-0.5"
                                                        title={`${report.attachments_count} screenshot${report.attachments_count === 1 ? '' : 's'}`}
                                                    >
                                                        <Paperclip className="size-3" />
                                                        {
                                                            report.attachments_count
                                                        }
                                                    </span>
                                                )}
                                                {report.has_reply && (
                                                    <span
                                                        className="inline-flex shrink-0 items-center"
                                                        title="Replied"
                                                    >
                                                        <MessageSquareReply className="size-3" />
                                                    </span>
                                                )}
                                            </span>
                                        </TableCell>
                                        <TableCell className="hidden max-w-60 lg:table-cell">
                                            <span className="block truncate text-sm">
                                                {report.reporter?.name ??
                                                    'Deleted account'}
                                            </span>
                                            <span className="block truncate text-xs text-muted-foreground">
                                                {report.organization?.name ??
                                                    'In no workspace'}
                                            </span>
                                        </TableCell>
                                        <TableCell className="hidden md:table-cell">
                                            <ImpactMeter
                                                impact={report.impact}
                                                label={report.impact_label}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <ReportStatusPill
                                                status={report.status}
                                                label={report.status_label}
                                            />
                                        </TableCell>
                                        <TableCell
                                            className="hidden text-right text-muted-foreground sm:table-cell"
                                            title={formatDateTime(
                                                report.created_at,
                                            )}
                                        >
                                            {formatRelative(report.created_at)}
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

            <ReportDialog id={list.openId} onClose={() => list.open(null)} />
        </PageBody>
    );
}
