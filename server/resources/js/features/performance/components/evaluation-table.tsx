import { Link } from '@inertiajs/react';
import { Eye, Gauge } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    rowOpens,
    SortableHead,
    TableCard,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { bandFor, formatPercent } from '../constants';
import { performanceRoutes } from '../routes';
import type { PerformanceEvaluation } from '../types';
import { BandChip } from './band-chip';
import { RatingLadder } from './rating-ladder';
import { EvaluationStatusBadge } from './status-badge';

export type EvaluationSort = 'name' | 'framework' | 'result' | 'status';

type Props = {
    evaluations: PerformanceEvaluation[];
    sort: EvaluationSort;
    direction: 'asc' | 'desc';
    onSort: (key: EvaluationSort) => void;
    /** What the empty table says — it differs by why it is empty. */
    empty: { title: string; description?: string; action?: ReactNode };
};

/**
 * The appraisals of a cycle. Each row carries the rating in the company's own
 * words and a miniature of the ladder it sits on, so a reader can compare two
 * people reviewed under two different frameworks without doing arithmetic in
 * their head. A row opens the scorecard.
 */
export function EvaluationTable({
    evaluations,
    sort,
    direction,
    onSort,
    empty,
}: Props) {
    const sortable = (key: EvaluationSort) => ({
        active: sort === key,
        direction,
        onSort: () => onSort(key),
    });

    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <SortableHead {...sortable('name')}>
                            Employee
                        </SortableHead>
                        <SortableHead
                            {...sortable('framework')}
                            className="hidden lg:table-cell"
                        >
                            Framework
                        </SortableHead>
                        <SortableHead {...sortable('result')} className="w-72">
                            Result
                        </SortableHead>
                        <SortableHead {...sortable('status')}>
                            Status
                        </SortableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {evaluations.length === 0 && (
                        <EmptyTableRow
                            colSpan={5}
                            icon={Gauge}
                            title={empty.title}
                            description={empty.description}
                            action={empty.action}
                        />
                    )}

                    {evaluations.map((evaluation) => {
                        const band =
                            bandFor(
                                evaluation.overall_percent,
                                evaluation.bands,
                            ) ?? null;
                        const href = performanceRoutes.show(evaluation.hashid);
                        const name =
                            evaluation.employee?.full_name ??
                            'Unknown employee';

                        return (
                            <TableRow key={evaluation.id} {...rowOpens(href)}>
                                <TableCell>
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <PersonAvatar
                                            name={name}
                                            initials={
                                                evaluation.employee?.initials ??
                                                '?'
                                            }
                                            photo={evaluation.employee?.photo}
                                            className="size-8"
                                            fallbackClassName="text-[11px]"
                                        />
                                        <div className="min-w-0">
                                            <Link
                                                href={href}
                                                className="block max-w-60 truncate text-sm font-medium hover:text-[#0ABFBF]"
                                            >
                                                {name}
                                            </Link>
                                            <p className="max-w-60 truncate text-xs text-muted-foreground">
                                                {[
                                                    evaluation.employee
                                                        ?.position,
                                                    evaluation.employee
                                                        ?.department,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ') ||
                                                    evaluation.employee
                                                        ?.employee_no ||
                                                    '—'}
                                            </p>
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell className="hidden max-w-48 truncate text-sm text-muted-foreground lg:table-cell">
                                    {evaluation.template_name ?? '—'}
                                </TableCell>
                                <TableCell>
                                    <div className="flex w-64 flex-col gap-1.5">
                                        <div className="flex items-center justify-between gap-2">
                                            <BandChip
                                                label={evaluation.result_label}
                                                tone={band?.tone}
                                            />
                                            <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                                {formatPercent(
                                                    evaluation.overall_percent,
                                                )}
                                            </span>
                                        </div>
                                        <RatingLadder
                                            bands={evaluation.bands}
                                            percent={evaluation.overall_percent}
                                            variant="rail"
                                        />
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <EvaluationStatusBadge
                                        status={evaluation.status}
                                    />
                                </TableCell>
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
                                            <DropdownMenuItem asChild>
                                                <Link href={href}>
                                                    <Eye className="size-4" />
                                                    Open scorecard
                                                </Link>
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </TableCell>
                            </TableRow>
                        );
                    })}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}
