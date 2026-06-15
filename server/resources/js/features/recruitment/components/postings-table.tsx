import {
    ArrowDown,
    ArrowUp,
    BriefcaseBusiness,
    ChevronsUpDown,
} from 'lucide-react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { TYPE_LABELS } from '../constants';
import type {
    ManagedPosting,
    PostingsFilters,
    RecruitmentPermissions,
} from '../types';
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
    onToggleSort: (column: string) => void;
};

export function PostingsTable({
    postings,
    filters,
    can,
    onToggleSort,
    ...handlers
}: Props) {
    return (
        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card dark:border-sidebar-border">
            <Table>
                <TableHeader className="bg-muted/40">
                    <TableRow className="hover:bg-transparent">
                        <SortHeader
                            column="title"
                            filters={filters}
                            onSort={onToggleSort}
                        >
                            Posting
                        </SortHeader>
                        <TableHead>Department</TableHead>
                        <TableHead>Type</TableHead>
                        <SortHeader
                            column="openings"
                            filters={filters}
                            onSort={onToggleSort}
                        >
                            Openings
                        </SortHeader>
                        <TableHead>Pipeline</TableHead>
                        <SortHeader
                            column="status"
                            filters={filters}
                            onSort={onToggleSort}
                        >
                            Status
                        </SortHeader>
                        <SortHeader
                            column="closing_date"
                            filters={filters}
                            onSort={onToggleSort}
                        >
                            Closing
                        </SortHeader>
                        <TableHead className="w-10 pr-4" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {postings.length === 0 && (
                        <TableRow className="hover:bg-transparent">
                            <TableCell colSpan={8} className="py-16">
                                <div className="flex flex-col items-center justify-center gap-2 text-center">
                                    <span className="flex size-12 items-center justify-center rounded-full bg-muted">
                                        <BriefcaseBusiness className="size-6 text-muted-foreground" />
                                    </span>
                                    <p className="text-sm font-medium">
                                        No job postings found
                                    </p>
                                    <p className="max-w-xs text-sm text-muted-foreground">
                                        Create a posting to start collecting
                                        applications.
                                    </p>
                                </div>
                            </TableCell>
                        </TableRow>
                    )}

                    {postings.map((posting) => (
                        <TableRow key={posting.id}>
                            <TableCell>
                                <button
                                    type="button"
                                    onClick={() => handlers.onView(posting)}
                                    className="flex flex-col text-left"
                                >
                                    <span className="font-medium hover:underline">
                                        {posting.title}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {posting.position?.title ??
                                            'No linked position'}
                                    </span>
                                </button>
                            </TableCell>
                            <TableCell className="text-sm">
                                {posting.department?.name ?? (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="text-sm text-muted-foreground">
                                {TYPE_LABELS[posting.employment_type]}
                            </TableCell>
                            <TableCell className="text-sm tabular-nums">
                                {posting.hired_count ?? 0}/{posting.openings}
                            </TableCell>
                            <TableCell>
                                <button
                                    type="button"
                                    onClick={() => handlers.onOpen(posting)}
                                    className="inline-flex items-center gap-1.5 rounded-md bg-muted/60 px-2 py-0.5 text-xs font-medium tabular-nums hover:bg-muted"
                                >
                                    <span className="text-[#0ABFBF]">
                                        {posting.open_count ?? 0}
                                    </span>
                                    <span className="text-muted-foreground">
                                        active ·
                                    </span>
                                    {posting.applications_count ?? 0} total
                                </button>
                            </TableCell>
                            <TableCell>
                                <PostingStatusBadge status={posting.status} />
                            </TableCell>
                            <TableCell className="text-sm text-muted-foreground">
                                {posting.closing_date ?? '—'}
                            </TableCell>
                            <TableCell className="pr-4 text-right">
                                <PostingRowActions
                                    posting={posting}
                                    can={can}
                                    {...handlers}
                                />
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function SortHeader({
    column,
    filters,
    onSort,
    children,
}: {
    column: string;
    filters: PostingsFilters;
    onSort: (column: string) => void;
    children: React.ReactNode;
}) {
    const active = filters.sort === column;

    return (
        <TableHead>
            <button
                type="button"
                onClick={() => onSort(column)}
                className={cn(
                    'inline-flex items-center gap-1 transition-colors hover:text-foreground',
                    active && 'text-foreground',
                )}
            >
                {children}
                {active ? (
                    filters.direction === 'asc' ? (
                        <ArrowUp className="size-3.5" />
                    ) : (
                        <ArrowDown className="size-3.5" />
                    )
                ) : (
                    <ChevronsUpDown className="size-3.5 opacity-40" />
                )}
            </button>
        </TableHead>
    );
}
