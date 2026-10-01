import { Link } from '@inertiajs/react';
import { BriefcaseBusiness, Plus } from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    rowOpens,
    SortableHead,
    TableCard,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TYPE_LABELS } from '../constants';
import { recruitmentRoutes } from '../routes';
import type {
    ManagedPosting,
    PostingsFilters,
    RecruitmentPermissions,
} from '../types';
import { PostingDeadline } from './posting-deadline';
import { PostingRowActions } from './posting-row-actions';
import { PostingStatusBadge } from './posting-status-badge';

type RowHandlers = {
    onView: (posting: ManagedPosting) => void;
    onOpen: (posting: ManagedPosting) => void;
    onEdit: (posting: ManagedPosting) => void;
    onStatus: (posting: ManagedPosting, status: string) => void;
    onDelete: (posting: ManagedPosting) => void;
};

type Props = RowHandlers & {
    postings: ManagedPosting[];
    filters: PostingsFilters;
    can: RecruitmentPermissions;
    filtered: boolean;
    onToggleSort: (column: string) => void;
    onCreate: () => void;
};

/**
 * Every job posting: where it is recruiting, how many seats are filled, and how
 * busy its pipeline is. A row opens that posting's pipeline; the menu has its
 * details, the public link, editing and status.
 */
export function PostingsTable({
    postings,
    filters,
    can,
    filtered,
    onToggleSort,
    onCreate,
    ...handlers
}: Props) {
    const sortable = (column: string) => ({
        active: filters.sort === column,
        direction: filters.direction,
        onSort: () => onToggleSort(column),
    });

    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <SortableHead {...sortable('title')}>
                            Posting
                        </SortableHead>
                        <TableHead>Department</TableHead>
                        <TableHead>Type</TableHead>
                        <SortableHead {...sortable('openings')} align="right">
                            Openings
                        </SortableHead>
                        <TableHead>Pipeline</TableHead>
                        <SortableHead {...sortable('status')}>
                            Status
                        </SortableHead>
                        <SortableHead {...sortable('closing_date')}>
                            Closing
                        </SortableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {postings.length === 0 && (
                        <EmptyTableRow
                            colSpan={8}
                            icon={BriefcaseBusiness}
                            title={
                                filtered
                                    ? 'No postings match'
                                    : 'No job postings yet'
                            }
                            description={
                                filtered
                                    ? 'Try adjusting the search or filters.'
                                    : 'Create a posting to start collecting applications.'
                            }
                            action={
                                !filtered &&
                                can.create && (
                                    <Button size="sm" onClick={onCreate}>
                                        <Plus className="size-4" />
                                        New posting
                                    </Button>
                                )
                            }
                        />
                    )}

                    {postings.map((posting) => (
                        <PostingRow
                            key={posting.id}
                            posting={posting}
                            can={can}
                            {...handlers}
                        />
                    ))}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}

function PostingRow({
    posting,
    can,
    ...handlers
}: RowHandlers & {
    posting: ManagedPosting;
    can: RecruitmentPermissions;
}) {
    const href = recruitmentRoutes.show(posting.hashid);

    return (
        <TableRow {...rowOpens(href)}>
            <TableCell>
                <Link
                    href={href}
                    className="block truncate text-sm font-medium hover:text-[#0ABFBF]"
                >
                    {posting.title}
                </Link>
                <span className="block truncate text-xs text-muted-foreground">
                    {posting.position?.title ?? 'No linked position'}
                </span>
            </TableCell>
            <TableCell className="text-sm">
                {posting.department?.name ?? (
                    <span className="text-muted-foreground">—</span>
                )}
            </TableCell>
            <TableCell className="text-sm whitespace-nowrap text-muted-foreground">
                {TYPE_LABELS[posting.employment_type]}
            </TableCell>
            <TableCell
                className="text-right text-sm tabular-nums"
                title={`${posting.hired_count ?? 0} of ${posting.openings} filled`}
            >
                {posting.hired_count ?? 0}
                <span className="text-muted-foreground">
                    /{posting.openings}
                </span>
            </TableCell>
            <TableCell className="text-sm whitespace-nowrap tabular-nums">
                <span className="font-medium text-[#0ABFBF]">
                    {posting.open_count ?? 0}
                </span>
                <span className="text-muted-foreground">
                    {' '}
                    active · {posting.applications_count ?? 0} total
                </span>
            </TableCell>
            <TableCell>
                <PostingStatusBadge status={posting.status} />
            </TableCell>
            <TableCell className="text-sm whitespace-nowrap">
                <PostingDeadline posting={posting} />
            </TableCell>
            <TableCell className="text-right">
                <PostingRowActions posting={posting} can={can} {...handlers} />
            </TableCell>
        </TableRow>
    );
}
