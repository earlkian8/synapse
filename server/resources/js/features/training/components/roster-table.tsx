import {
    ArrowDown,
    ArrowUp,
    CheckCircle2,
    ChevronsUpDown,
    Download,
    MoreHorizontal,
    Pencil,
    Search,
    Trash2,
    UserMinus,
    UserPlus,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
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
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
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
import type {
    TrainingEnrollment,
    TrainingEnrollmentStatus,
} from '../types';
import { EnrollmentStatusBadge } from './training-status-badge';

type RosterSort = 'name' | 'status' | 'score' | 'enrolled';

const STATUS_RANK: Record<TrainingEnrollmentStatus, number> = {
    enrolled: 0,
    completed: 1,
    dropped: 2,
};

type Props = {
    programHashid: string;
    enrollments: TrainingEnrollment[];
    canManage: boolean;
    onEnroll: () => void;
    onEdit: (enrollment: TrainingEnrollment) => void;
    onRemove: (enrollment: TrainingEnrollment) => void;
};

/**
 * The program roster as a searchable, sortable table with multi-select bulk
 * actions — mark many completed / dropped, or remove them at once — beyond the
 * per-row status / score edit.
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
            setDirection(key === 'score' ? 'desc' : 'asc');
        }
    };

    const filtered = useMemo(() => {
        const needle = search.trim().toLowerCase();
        const dir = direction === 'asc' ? 1 : -1;

        return enrollments
            .filter((enrollment) => {
                if (status !== 'all' && enrollment.status !== status) {
                    return false;
                }

                if (
                    needle !== '' &&
                    !(enrollment.employee?.full_name ?? '')
                        .toLowerCase()
                        .includes(needle)
                ) {
                    return false;
                }

                return true;
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
                            ((a.enrolled_on ?? '') > (b.enrolled_on ?? '')
                                ? 1
                                : -1) * dir
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

    const allVisibleSelected =
        filtered.length > 0 && filtered.every((e) => selected.has(e.id));

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

            if (allVisibleSelected) {
                for (const e of filtered) {
                    next.delete(e.id);
                }
            } else {
                for (const e of filtered) {
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
            {/* Toolbar */}
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <div className="relative sm:max-w-xs sm:flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search roster…"
                        className="pl-9"
                    />
                    {search !== '' && (
                        <button
                            type="button"
                            onClick={() => setSearch('')}
                            className="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                            aria-label="Clear search"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>
                <Select
                    value={status}
                    onValueChange={(v) =>
                        setStatus(v as TrainingEnrollmentStatus | 'all')
                    }
                >
                    <SelectTrigger className="sm:w-40">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All statuses</SelectItem>
                        <SelectItem value="enrolled">Enrolled</SelectItem>
                        <SelectItem value="completed">Completed</SelectItem>
                        <SelectItem value="dropped">Dropped</SelectItem>
                    </SelectContent>
                </Select>
                <div className="flex items-center gap-2 sm:ml-auto">
                    <Button variant="outline" size="sm" asChild>
                        <a href={trainingRoutes.rosterExport(programHashid)}>
                            <Download className="size-4" />
                            Export
                        </a>
                    </Button>
                    {canManage && (
                        <Button size="sm" onClick={onEnroll}>
                            <UserPlus className="size-4" />
                            Enroll
                        </Button>
                    )}
                </div>
            </div>

            {/* Bulk action bar */}
            {canManage && selected.size > 0 && (
                <div className="flex flex-wrap items-center gap-2 rounded-lg border border-[#0ABFBF]/30 bg-[#0ABFBF]/[0.06] px-3 py-2">
                    <span className="text-sm font-medium tabular-nums">
                        {selected.size} selected
                    </span>
                    <div className="ml-auto flex flex-wrap items-center gap-1.5">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={processing}
                            onClick={() => runBulk('complete')}
                        >
                            <CheckCircle2 className="size-4 text-emerald-500" />
                            Mark completed
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={processing}
                            onClick={() => runBulk('drop')}
                        >
                            <UserMinus className="size-4 text-amber-500" />
                            Mark dropped
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            className="text-muted-foreground hover:text-destructive"
                            disabled={processing}
                            onClick={() => setConfirmRemove(true)}
                        >
                            <Trash2 className="size-4" />
                            Remove
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => setSelected(new Set())}
                        >
                            Clear
                        </Button>
                    </div>
                </div>
            )}

            {/* Table */}
            <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-card shadow-sm dark:border-sidebar-border">
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader className="bg-muted/40">
                            <TableRow className="hover:bg-transparent">
                                {canManage && (
                                    <TableHead className="w-10 pl-4">
                                        <Checkbox
                                            checked={allVisibleSelected}
                                            onCheckedChange={toggleAll}
                                            disabled={filtered.length === 0}
                                            aria-label="Select all"
                                        />
                                    </TableHead>
                                )}
                                <SortHead
                                    label="Employee"
                                    sortKey="name"
                                    sort={sort}
                                    direction={direction}
                                    onSort={onSort}
                                />
                                <SortHead
                                    label="Status"
                                    sortKey="status"
                                    sort={sort}
                                    direction={direction}
                                    onSort={onSort}
                                />
                                <SortHead
                                    label="Score"
                                    sortKey="score"
                                    sort={sort}
                                    direction={direction}
                                    onSort={onSort}
                                    align="right"
                                />
                                <SortHead
                                    label="Enrolled"
                                    sortKey="enrolled"
                                    sort={sort}
                                    direction={direction}
                                    onSort={onSort}
                                    className="hidden md:table-cell"
                                />
                                <TableHead className="hidden lg:table-cell">
                                    Completed
                                </TableHead>
                                {canManage && (
                                    <TableHead className="w-10 pr-4" />
                                )}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {filtered.length === 0 ? (
                                <TableRow className="hover:bg-transparent">
                                    <TableCell
                                        colSpan={canManage ? 7 : 5}
                                        className="py-10 text-center text-sm text-muted-foreground"
                                    >
                                        No one matches these filters.
                                    </TableCell>
                                </TableRow>
                            ) : (
                                filtered.map((enrollment) => {
                                    const checked = selected.has(enrollment.id);

                                    return (
                                        <TableRow
                                            key={enrollment.id}
                                            data-state={
                                                checked ? 'selected' : undefined
                                            }
                                        >
                                            {canManage && (
                                                <TableCell className="pl-4">
                                                    <Checkbox
                                                        checked={checked}
                                                        onCheckedChange={() =>
                                                            toggle(
                                                                enrollment.id,
                                                            )
                                                        }
                                                        aria-label={`Select ${enrollment.employee?.full_name ?? 'employee'}`}
                                                    />
                                                </TableCell>
                                            )}
                                            <TableCell>
                                                <div className="flex items-center gap-2.5">
                                                    <PersonAvatar
                                                        name={
                                                            enrollment.employee
                                                                ?.full_name ??
                                                            'Unknown'
                                                        }
                                                        initials={
                                                            enrollment.employee
                                                                ?.initials ?? '?'
                                                        }
                                                        photo={
                                                            enrollment.employee
                                                                ?.photo
                                                        }
                                                        className="size-8"
                                                    />
                                                    <div className="min-w-0">
                                                        <p className="truncate text-sm font-medium">
                                                            {enrollment.employee
                                                                ?.full_name ??
                                                                'Unknown'}
                                                        </p>
                                                        <p className="truncate text-xs text-muted-foreground">
                                                            {enrollment.employee
                                                                ?.position ??
                                                                enrollment
                                                                    .employee
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
                                                {formatDate(
                                                    enrollment.enrolled_on,
                                                )}
                                            </TableCell>
                                            <TableCell className="hidden text-sm text-muted-foreground lg:table-cell">
                                                {formatDate(
                                                    enrollment.completed_at,
                                                )}
                                            </TableCell>
                                            {canManage && (
                                                <TableCell className="pr-4 text-right">
                                                    <DropdownMenu>
                                                        <DropdownMenuTrigger
                                                            asChild
                                                        >
                                                            <button
                                                                type="button"
                                                                className="ml-auto rounded-md p-1 text-muted-foreground transition-colors hover:bg-muted data-[state=open]:bg-muted"
                                                                aria-label="Enrollment actions"
                                                            >
                                                                <MoreHorizontal className="size-4" />
                                                            </button>
                                                        </DropdownMenuTrigger>
                                                        <DropdownMenuContent
                                                            align="end"
                                                            className="w-40"
                                                        >
                                                            <DropdownMenuItem
                                                                onSelect={() =>
                                                                    onEdit(
                                                                        enrollment,
                                                                    )
                                                                }
                                                            >
                                                                <Pencil className="size-4" />
                                                                Edit
                                                            </DropdownMenuItem>
                                                            <DropdownMenuSeparator />
                                                            <DropdownMenuItem
                                                                variant="destructive"
                                                                onSelect={() =>
                                                                    onRemove(
                                                                        enrollment,
                                                                    )
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
                                })
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>

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

function SortHead({
    label,
    sortKey,
    sort,
    direction,
    onSort,
    align = 'left',
    className,
}: {
    label: string;
    sortKey: RosterSort;
    sort: RosterSort;
    direction: 'asc' | 'desc';
    onSort: (key: RosterSort) => void;
    align?: 'left' | 'right';
    className?: string;
}) {
    const active = sort === sortKey;

    return (
        <TableHead className={cn(align === 'right' && 'text-right', className)}>
            <button
                type="button"
                onClick={() => onSort(sortKey)}
                className={cn(
                    'inline-flex items-center gap-1 transition-colors hover:text-foreground',
                    align === 'right' && 'flex-row-reverse',
                    active && 'text-foreground',
                )}
            >
                {label}
                {active ? (
                    direction === 'asc' ? (
                        <ArrowUp className="size-3.5" />
                    ) : (
                        <ArrowDown className="size-3.5" />
                    )
                ) : (
                    <ChevronsUpDown className="size-3.5 opacity-50" />
                )}
            </button>
        </TableHead>
    );
}
