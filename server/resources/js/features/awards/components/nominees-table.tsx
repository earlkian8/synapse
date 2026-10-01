import { ChevronDown, History, Plus, Users } from 'lucide-react';
import { Fragment, useState } from 'react';
import {
    DataTable,
    EmptyTableRow,
    rowOpens,
    TableCard,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import {
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { BAND_LABELS, BAND_TONES } from '../constants';
import type { AwardNomination } from '../types';
import { Breakdown, ContributionBar, RankBadge } from './nomination-parts';

type Props = {
    nomination: AwardNomination;
    canManage: boolean;
    onGive: (employeeId: number, typeId: number) => void;
};

/**
 * One award's ranked shortlist: rank, person, score and the bar of what it is
 * made of. A row expands into the transparent breakdown — the front-runner's
 * opens by default.
 */
export function NomineesTable({ nomination, canManage, onGive }: Props) {
    const { type, nominees } = nomination;
    const [open, setOpen] = useState<number[]>(
        nominees[0] ? [nominees[0].employee.id] : [],
    );
    const columns = canManage ? 6 : 5;

    const toggle = (id: number) =>
        setOpen((current) =>
            current.includes(id)
                ? current.filter((x) => x !== id)
                : [...current, id],
        );

    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-12">Rank</TableHead>
                        <TableHead>Employee</TableHead>
                        <TableHead className="text-right">Score</TableHead>
                        <TableHead className="w-64">
                            What it is made of
                        </TableHead>
                        <TableHead>Last won</TableHead>
                        {canManage && <TableHead className="w-24" />}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {nominees.length === 0 && (
                        <EmptyTableRow
                            colSpan={columns}
                            icon={Users}
                            title="No signals to rank yet"
                            description="Appraisals, attendance and trainings feed this shortlist."
                        />
                    )}

                    {nominees.map((nominee) => {
                        const expanded = open.includes(nominee.employee.id);
                        const detailsId = `nominee-${type.id}-${nominee.employee.id}`;
                        const opens = rowOpens(() =>
                            toggle(nominee.employee.id),
                        );

                        return (
                            <Fragment key={nominee.employee.id}>
                                <TableRow
                                    {...opens}
                                    aria-expanded={expanded}
                                    aria-controls={detailsId}
                                    className={cn(
                                        opens.className,
                                        nominee.rank === 1 && 'bg-muted/30',
                                        expanded && 'border-b-0',
                                    )}
                                >
                                    <TableCell>
                                        <RankBadge rank={nominee.rank} />
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex min-w-0 items-center gap-2.5">
                                            <PersonAvatar
                                                name={
                                                    nominee.employee.full_name
                                                }
                                                initials={
                                                    nominee.employee.initials
                                                }
                                                photo={nominee.employee.photo}
                                                className="size-8"
                                                fallbackClassName="text-[11px]"
                                            />
                                            <div className="min-w-0">
                                                <p className="flex max-w-60 items-center gap-1.5 truncate text-sm font-medium">
                                                    {nominee.employee.full_name}
                                                    <ChevronDown
                                                        className={cn(
                                                            'size-3.5 shrink-0 text-muted-foreground transition-transform',
                                                            expanded &&
                                                                'rotate-180',
                                                        )}
                                                        aria-hidden="true"
                                                    />
                                                </p>
                                                <p className="max-w-60 truncate text-xs text-muted-foreground">
                                                    {[
                                                        nominee.employee
                                                            .position,
                                                        nominee.employee
                                                            .department,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' · ') ||
                                                        nominee.employee
                                                            .employee_no}
                                                </p>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <span className="inline-flex flex-col items-end">
                                            <span
                                                className={cn(
                                                    'text-sm font-semibold tabular-nums',
                                                    BAND_TONES[nominee.band],
                                                )}
                                            >
                                                {nominee.score}
                                            </span>
                                            <span className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                                {BAND_LABELS[nominee.band]}
                                            </span>
                                        </span>
                                    </TableCell>
                                    <TableCell>
                                        <ContributionBar nominee={nominee} />
                                    </TableCell>
                                    <TableCell>
                                        {nominee.recent_winner ? (
                                            <span className="inline-flex items-center gap-1 rounded-full border border-amber-500/30 bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-medium text-amber-600 dark:text-amber-400">
                                                <History className="size-3" />
                                                {nominee.won_months_ago === 0
                                                    ? 'This month'
                                                    : `${nominee.won_months_ago}mo ago`}
                                            </span>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell className="text-right">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="h-7 px-2 text-xs"
                                                onClick={() =>
                                                    onGive(
                                                        nominee.employee.id,
                                                        type.id,
                                                    )
                                                }
                                            >
                                                <Plus className="size-3.5" />
                                                Give
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                                {expanded && (
                                    <TableRow
                                        id={detailsId}
                                        className={cn(
                                            'hover:bg-transparent',
                                            nominee.rank === 1 && 'bg-muted/30',
                                        )}
                                    >
                                        <TableCell />
                                        <TableCell
                                            colSpan={columns - 1}
                                            className="pt-0 pb-3 whitespace-normal"
                                        >
                                            <Breakdown nominee={nominee} />
                                        </TableCell>
                                    </TableRow>
                                )}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}
