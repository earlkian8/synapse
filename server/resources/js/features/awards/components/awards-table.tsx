import { Award, Pencil, Trash2 } from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    TableCard,
} from '@/components/data-table';
import { PersonAvatar } from '@/components/person-avatar';
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
import { formatDate } from '../constants';
import type { EmployeeAward } from '../types';
import { AwardTypeBadge } from './award-type-badge';

type Props = {
    awards: EmployeeAward[];
    canManage: boolean;
    filtered: boolean;
    onEdit: (award: EmployeeAward) => void;
    onRemove: (award: EmployeeAward) => void;
};

/** The recognition feed: who was recognised, with what, why, when and by whom. */
export function AwardsTable({
    awards,
    canManage,
    filtered,
    onEdit,
    onRemove,
}: Props) {
    return (
        <TableCard>
            <DataTable>
                <TableHeader>
                    <TableRow>
                        <TableHead>Employee</TableHead>
                        <TableHead>Award</TableHead>
                        <TableHead>Reason</TableHead>
                        <TableHead>Awarded</TableHead>
                        <TableHead>Given by</TableHead>
                        {canManage && <TableHead className="w-10" />}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {awards.length === 0 && (
                        <EmptyTableRow
                            colSpan={canManage ? 6 : 5}
                            icon={Award}
                            title={
                                filtered
                                    ? 'No recognitions match'
                                    : 'No recognitions yet'
                            }
                            description={
                                filtered
                                    ? 'Try another award type, or clear the search.'
                                    : canManage
                                      ? 'Add award types under Company Setup → Award Types, then give recognition here.'
                                      : 'No recognitions have been given yet.'
                            }
                        />
                    )}

                    {awards.map((award) => (
                        <TableRow key={award.id}>
                            <TableCell>
                                <div className="flex min-w-0 items-center gap-2.5">
                                    <PersonAvatar
                                        name={
                                            award.employee?.full_name ??
                                            'Unknown'
                                        }
                                        initials={
                                            award.employee?.initials ?? '?'
                                        }
                                        photo={award.employee?.photo}
                                        className="size-8"
                                        fallbackClassName="text-[11px]"
                                    />
                                    <div className="min-w-0">
                                        <p className="max-w-52 truncate text-sm font-medium">
                                            {award.employee?.full_name ??
                                                'Unknown employee'}
                                        </p>
                                        <p className="max-w-52 truncate text-xs text-muted-foreground">
                                            {award.employee?.department ??
                                                award.employee?.position ??
                                                '—'}
                                        </p>
                                    </div>
                                </div>
                            </TableCell>
                            <TableCell>
                                {award.award_type ? (
                                    <AwardTypeBadge
                                        name={award.award_type.name}
                                        color={award.award_type.color}
                                    />
                                ) : (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="whitespace-normal">
                                <p
                                    className="line-clamp-2 max-w-md text-sm text-muted-foreground"
                                    title={award.reason ?? undefined}
                                >
                                    {award.reason ?? '—'}
                                </p>
                            </TableCell>
                            <TableCell className="text-sm text-muted-foreground tabular-nums">
                                {formatDate(award.awarded_on)}
                            </TableCell>
                            <TableCell className="text-sm text-muted-foreground">
                                {award.granted_by?.name ?? '—'}
                            </TableCell>
                            {canManage && (
                                <TableCell className="text-right">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <RowMenuTrigger
                                                label={`Actions for ${award.employee?.full_name ?? 'this recognition'}`}
                                            />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            className="w-44"
                                        >
                                            <DropdownMenuItem
                                                onSelect={() => onEdit(award)}
                                            >
                                                <Pencil className="size-4" />
                                                Edit
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                variant="destructive"
                                                onSelect={() => onRemove(award)}
                                            >
                                                <Trash2 className="size-4" />
                                                Remove
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </DataTable>
        </TableCard>
    );
}
