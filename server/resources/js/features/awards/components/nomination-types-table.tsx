import { Award, Plus, Trophy, Users } from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    rowOpens,
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
import { cn } from '@/lib/utils';
import {
    awardColorStyle,
    BAND_LABELS,
    BAND_TONES,
    DEFAULT_AWARD_COLOR,
} from '../constants';
import type { AwardNomination } from '../types';

type Props = {
    board: AwardNomination[];
    canManage: boolean;
    onOpen: (typeId: number) => void;
    onGive: (employeeId: number, typeId: number) => void;
};

/**
 * The nomination board's first level: one row per award type — what it
 * celebrates, how many people are in the running, and who leads. A row opens
 * that award's ranked shortlist.
 */
export function NominationTypesTable({
    board,
    canManage,
    onOpen,
    onGive,
}: Props) {
    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead>Award</TableHead>
                        <TableHead>Weighs</TableHead>
                        <TableHead className="text-right">Nominees</TableHead>
                        <TableHead>Front-runner</TableHead>
                        <TableHead className="text-right">Score</TableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {board.length === 0 && (
                        <EmptyTableRow
                            colSpan={6}
                            icon={Trophy}
                            title="No active award types"
                            description="Add award types under Company Setup → Award Types and the board will rank who deserves each one."
                        />
                    )}

                    {board.map(({ type, profile, nominees }) => {
                        const leader = nominees[0] ?? null;
                        const accent = type.color ?? DEFAULT_AWARD_COLOR;

                        return (
                            <TableRow
                                key={type.id}
                                {...rowOpens(() => onOpen(type.id))}
                            >
                                <TableCell>
                                    <div className="flex min-w-0 items-center gap-2.5">
                                        <span
                                            className="flex size-8 shrink-0 items-center justify-center rounded-lg"
                                            style={{
                                                backgroundColor: `${accent}1a`,
                                                color: accent,
                                            }}
                                        >
                                            <Award className="size-4" />
                                        </span>
                                        <div className="min-w-0">
                                            <button
                                                type="button"
                                                onClick={() => onOpen(type.id)}
                                                className="max-w-64 truncate text-left text-sm font-medium hover:text-[#0ABFBF]"
                                            >
                                                {type.name}
                                            </button>
                                            {type.description && (
                                                <p className="line-clamp-1 max-w-sm text-xs whitespace-normal text-muted-foreground">
                                                    {type.description}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <span
                                        className="inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium"
                                        style={awardColorStyle(type.color)}
                                        title={profile.hint}
                                    >
                                        {profile.label}
                                    </span>
                                </TableCell>
                                <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                                    <span className="inline-flex items-center gap-1">
                                        <Users className="size-3.5" />
                                        {nominees.length}
                                    </span>
                                </TableCell>
                                <TableCell>
                                    {leader ? (
                                        <div className="flex min-w-0 items-center gap-2">
                                            <PersonAvatar
                                                name={leader.employee.full_name}
                                                initials={
                                                    leader.employee.initials
                                                }
                                                photo={leader.employee.photo}
                                                className="size-7"
                                                fallbackClassName="text-[10px]"
                                            />
                                            <span className="max-w-48 truncate text-sm">
                                                {leader.employee.full_name}
                                            </span>
                                        </div>
                                    ) : (
                                        <span className="text-xs text-muted-foreground">
                                            No signals to rank yet
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-right">
                                    {leader && (
                                        <span className="inline-flex flex-col items-end">
                                            <span
                                                className={cn(
                                                    'text-sm font-semibold tabular-nums',
                                                    BAND_TONES[leader.band],
                                                )}
                                            >
                                                {leader.score}
                                            </span>
                                            <span className="text-[10px] tracking-wide text-muted-foreground uppercase">
                                                {BAND_LABELS[leader.band]}
                                            </span>
                                        </span>
                                    )}
                                </TableCell>
                                <TableCell className="text-right">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <RowMenuTrigger
                                                label={`Actions for ${type.name}`}
                                            />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            className="w-56"
                                        >
                                            <DropdownMenuItem
                                                onSelect={() => onOpen(type.id)}
                                            >
                                                <Users className="size-4" />
                                                View nominees
                                            </DropdownMenuItem>
                                            {canManage && leader && (
                                                <DropdownMenuItem
                                                    onSelect={() =>
                                                        onGive(
                                                            leader.employee.id,
                                                            type.id,
                                                        )
                                                    }
                                                >
                                                    <Plus className="size-4" />
                                                    Give to the front-runner
                                                </DropdownMenuItem>
                                            )}
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
