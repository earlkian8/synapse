import {
    CheckCircle2,
    Download,
    Pencil,
    Trash2,
    UserMinus,
    UserPlus,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    DataTable,
    EmptyTableRow,
    FilterSelect,
    RowMenuTrigger,
    rowOpens,
    SearchInput,
    SortableHead,
    TableCard,
    TablePagination,
    useClientPagination,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { bulkEnrollments } from '../api';
import { formatDate, scoreTone } from '../constants';
import { trainingRoutes } from '../routes';
import type { TrainingEnrollment, TrainingEnrollmentStatus } from '../types';
import { EnrollmentStatusBadge } from './training-status-badge';

type RosterSort = 'name' | 'status' | 'score' | 'enrolled' | 'completed';

const STATUS_RANK: Record<TrainingEnrollmentStatus, number> = {
    enrolled: 0,
    completed: 1,
    dropped: 2,
};

const STATUS_OPTIONS = [
    { value: 'all', label: 'All statuses' },
    { value: 'enrolled', label: 'Enrolled' },
    { value: 'completed', label: 'Completed' },
    { value: 'dropped', label: 'Dropped' },
];

type Props = {
    programHashid: string;
    enrollments: TrainingEnrollment[];
    canManage: boolean;
    onEnroll: () => void;
    onEdit: (enrollment: TrainingEnrollment) => void;
    onRemove: (enrollment: TrainingEnrollment) => void;
};

/**
 * The program roster: a searchable, sortable table with multi-select bulk
 * actions — mark many completed or dropped, or remove them at once. For a
 * manager, a row opens that enrollment's status and score.
 */
export function RosterTable({
    programHashid,
    enrollments,
    canManage,
    onEnroll,
    onEdit,
    onRemove,
}: Props) {
    const [search, setSearch] = useState('');
    const [status, setStatus] = useState<TrainingEnrollmentStatus | 'all'>(
        'all',
    );
    const [sort, setSort] = useState<RosterSort>('name');
    const [direction, setDirection] = useState<'asc' | 'desc'>('asc');
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [processing, setProcessing] = useState(false);
    const [confirmRemove, setConfirmRemove] = useState(false);

    const onSort = (key: RosterSort) => {
        if (key === sort) {
            setDirection((d) => (d === 'asc' ? 'desc' : 'asc'));
        } else {
            setSort(key);
            setDirection(key === 'name' || key === 'status' ? 'asc' : 'desc');
        }
    };

    const sortable = (key: RosterSort) => ({
        active: sort === key,
        direction,
        onSort: () => onSort(key),
    });

    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();
        const dir = direction === 'asc' ? 1 : -1;

        return enrollments
            .filter((enrollment) => {
                if (status !== 'all' && enrollment.status !== status) {
                    return false;
                }

                return (
                    needle === '' ||
                    (enrollment.employee?.full_name ?? '')
                        .toLowerCase()
                        .includes(needle) ||
                    (enrollment.employee?.position ?? '')
                        .toLowerCase()
                        .includes(needle)
                );
            })
            .sort((a, b) => {
                switch (sort) {
                    case 'status':
                        return (
                            (STATUS_RANK[a.status] - STATUS_RANK[b.status]) *
                            dir
                        );
                    case 'score':
                        return ((a.score ?? -1) - (b.score ?? -1)) * dir;
                    case 'enrolled':
                        return (
                            (a.enrolled_on ?? '').localeCompare(
                                b.enrolled_on ?? '',
                            ) * dir
                        );
                    case 'completed':
                        return (
                            (a.completed_at ?? '').localeCompare(
                                b.completed_at ?? '',
                            ) * dir
                        );
                    default:
                        return (
                            (a.employee?.full_name ?? '').localeCompare(
                                b.employee?.full_name ?? '',
                            ) * dir
                        );
                }
            });
    }, [enrollments, search, status, sort, direction]);

    const page = useClientPagination(
        filtered,
        [search, status, sort, direction].join('|'),
    );

    const isFiltered = search !== '' || status !== 'all';
    const columns = canManage ? 7 : 5;

    // "Select all" covers the rows on screen, so a bulk action never reaches
    // people the manager cannot see.
    const allVisibleSelected =
        page.rows.length > 0 && page.rows.every((e) => selected.has(e.id));

    const toggle = (id: number) =>
        setSelected((prev) => {
            const next = new Set(prev);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });

    const toggleAll = () =>
        setSelected((prev) => {
            const next = new Set(prev);

            for (const e of page.rows) {
                if (allVisibleSelected) {
                    next.delete(e.id);
                } else {
                    next.add(e.id);
                }
            }

            return next;
        });

    const runBulk = (action: 'complete' | 'drop' | 'remove') => {
        bulkEnrollments(action, [...selected], {
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setConfirmRemove(false);
            },
            onSuccess: () => setSelected(new Set()),
        });
    };

    return (
        <div className="flex flex-col gap-3">
            <TableCard
                title="Roster"
                count={enrollments.length}
                actions={
                    <>
                        {enrollments.length > 0 && (
                            <>
                                <SearchInput
                                    value={search}
                                    onSearch={setSearch}
                                    delay={0}
                                    placeholder="Search roster…"
                                    label="Search the roster"
                                    className="sm:w-56"
                                />
                                <FilterSelect
                                    label="Filter by enrollment status"
                                    value={status}
                                    onChange={(value) =>
                                        setStatus(
                                            value as
                                                | TrainingEnrollmentStatus
                                                | 'all',
                                        )
                                    }
                                    options={STATUS_OPTIONS}
                                    className="w-36"
                                />
                                <Button variant="outline" size="sm" asChild>
                                    <a
                                        href={trainingRoutes.rosterExport(
                                            programHashid,
                                        )}
                                    >
                                        <Download className="size-4" />
                                        Export
                                    </a>
                                </Button>
                            </>
                        )}
                        {canManage && (
                            <Button size="sm" onClick={onEnroll}>
                                <UserPlus className="size-4" />
                                Enroll
                            </Button>
                        )}
                    </>
                }
            >
                {canManage && selected.size > 0 && (
                    <div className="flex flex-wrap items-center gap-2 border-b border-[#0ABFBF]/30 bg-[#0ABFBF]/6 px-4 py-1.5">
                        <span className="text-sm font-medium tabular-nums">
                            {selected.size} selected
                        </span>
                        <div className="ml-auto flex flex-wrap items-center gap-1.5">
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-7"
                                disabled={processing}
                                onClick={() => runBulk('complete')}
                            >
                                <CheckCircle2 className="size-4 text-emerald-500" />
                                Mark completed
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-7"
                                disabled={processing}
                                onClick={() => runBulk('drop')}
                            >
                                <UserMinus className="size-4 text-amber-500" />
                                Mark dropped
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-7 text-muted-foreground hover:text-destructive"
                                disabled={processing}
                                onClick={() => setConfirmRemove(true)}
                            >
                                <Trash2 className="size-4" />
                                Remove
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-7"
                                onClick={() => setSelected(new Set())}
                            >
                                Clear
                            </Button>
                        </div>
                    </div>
                )}

                <DataTable>
                    <TableHeader>
                        <TableRow>
                            {canManage && (
                                <TableHead className="w-10">
                                    <Checkbox
                                        checked={allVisibleSelected}
                                        onCheckedChange={toggleAll}
                                        disabled={page.rows.length === 0}
                                        aria-label="Select everyone on this page"
                                    />
                                </TableHead>
                            )}
                            <SortableHead {...sortable('name')}>
                                Employee
                            </SortableHead>
                            <SortableHead {...sortable('status')}>
                                Status
                            </SortableHead>
                            <SortableHead {...sortable('score')} align="right">
                                Score
                            </SortableHead>
                            <SortableHead
                                {...sortable('enrolled')}
                                className="hidden md:table-cell"
                            >
                                Enrolled
                            </SortableHead>
                            <SortableHead
                                {...sortable('completed')}
                                className="hidden lg:table-cell"
                            >
                                Completed
                            </SortableHead>
                            {canManage && <TableHead className="w-10" />}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {page.rows.length === 0 && (
                            <EmptyTableRow
                                colSpan={columns}
                                icon={Users}
                                title={
                                    enrollments.length === 0
                                        ? 'No one enrolled yet'
                                        : 'No one matches'
                                }
                                description={
                                    enrollments.length === 0
                                        ? canManage
                                            ? 'Use "Enroll" to add people to this program.'
                                            : 'No employees are enrolled in this program.'
                                        : isFiltered
                                          ? 'Try another status, or clear the search.'
                                          : undefined
                                }
                                action={
                                    enrollments.length === 0 && canManage ? (
                                        <Button size="sm" onClick={onEnroll}>
                                            <UserPlus className="size-4" />
                                            Enroll employees
                                        </Button>
                                    ) : undefined
                                }
                            />
                        )}

                        {page.rows.map((enrollment) => {
                            const checked = selected.has(enrollment.id);
                            const name =
                                enrollment.employee?.full_name ?? 'Unknown';
                            const opens = rowOpens(() => onEdit(enrollment));

                            return (
                                <TableRow
                                    key={enrollment.id}
                                    data-state={
                                        checked ? 'selected' : undefined
                                    }
                                    {...(canManage ? opens : {})}
                                    className={cn(canManage && opens.className)}
                                >
                                    {canManage && (
                                        <TableCell>
                                            <Checkbox
                                                checked={checked}
                                                onCheckedChange={() =>
                                                    toggle(enrollment.id)
                                                }
                                                aria-label={`Select ${name}`}
                                            />
                                        </TableCell>
                                    )}
                                    <TableCell>
                                        <div className="flex min-w-0 items-center gap-2.5">
                                            <PersonAvatar
                                                name={name}
                                                initials={
                                                    enrollment.employee
                                                        ?.initials ?? '?'
                                                }
                                                photo={
                                                    enrollment.employee?.photo
                                                }
                                                className="size-8"
                                                fallbackClassName="text-[11px]"
                                            />
                                            <div className="min-w-0">
                                                <p className="max-w-60 truncate text-sm font-medium">
                                                    {name}
                                                </p>
                                                <p className="max-w-60 truncate text-xs text-muted-foreground">
                                                    {enrollment.employee
                                                        ?.position ??
                                                        enrollment.employee
                                                            ?.employee_no ??
                                                        '—'}
                                                </p>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <EnrollmentStatusBadge
                                            status={enrollment.status}
                                        />
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-right text-sm font-semibold tabular-nums',
                                            scoreTone(enrollment.score),
                                        )}
                                    >
                                        {enrollment.score === null
                                            ? '—'
                                            : `${enrollment.score}%`}
                                    </TableCell>
                                    <TableCell className="hidden text-sm text-muted-foreground md:table-cell">
                                        {formatDate(enrollment.enrolled_on)}
                                    </TableCell>
                                    <TableCell className="hidden text-sm text-muted-foreground lg:table-cell">
                                        {formatDate(enrollment.completed_at)}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <RowMenuTrigger
                                                        label={`Actions for ${name}`}
                                                    />
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent
                                                    align="end"
                                                    className="w-44"
                                                >
                                                    <DropdownMenuItem
                                                        onSelect={() =>
                                                            onEdit(enrollment)
                                                        }
                                                    >
                                                        <Pencil className="size-4" />
                                                        Status & score
                                                    </DropdownMenuItem>
                                                    <DropdownMenuSeparator />
                                                    <DropdownMenuItem
                                                        variant="destructive"
                                                        onSelect={() =>
                                                            onRemove(enrollment)
                                                        }
                                                    >
                                                        <Trash2 className="size-4" />
                                                        Remove
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </TableCell>
                                    )}
                                </TableRow>
                            );
                        })}
                    </TableBody>
                </DataTable>
            </TableCard>

            <TablePagination
                meta={page.meta}
                perPage={page.perPage}
                onPage={page.setPage}
                onPerPage={page.setPerPage}
            />

            <ConfirmDialog
                open={confirmRemove}
                onOpenChange={setConfirmRemove}
                title={`Remove ${selected.size} enrollment${selected.size === 1 ? '' : 's'}?`}
                description="The selected employees will be removed from this program. This can't be undone."
                confirmLabel="Remove"
                destructive
                processing={processing}
                onConfirm={() => runBulk('remove')}
            />
        </div>
    );
}
