import { Archive, CalendarDays, MapPin, Pencil, Repeat } from 'lucide-react';
import {
    DataTable,
    EmptyTableRow,
    RowMenuTrigger,
    rowOpens,
} from '@/components/data-table';
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
import { formatHolidayDate, untilLabel } from '../constants';
import type { Holiday } from '../types';
import { HolidayTypeBadge } from './holiday-type-badge';

/**
 * The holiday calendar as a table, soonest first (ADR 0078): when each falls
 * next, how it repeats — a fixed date, Holy Week from Easter, the n-th weekday
 * of a month — its type, and who keeps it. A one-off that has passed sinks to
 * the bottom. A row opens the holiday to edit, for those who may.
 */
export function HolidayTable({
    holidays,
    canManage,
    onEdit,
    onArchive,
}: {
    holidays: Holiday[];
    canManage: boolean;
    onEdit: (holiday: Holiday) => void;
    onArchive: (holiday: Holiday) => void;
}) {
    const rows = [...holidays].sort((a, b) => {
        if (a.next_date && b.next_date) {
            return a.next_date.localeCompare(b.next_date);
        }

        return a.next_date ? -1 : b.next_date ? 1 : 0;
    });

    return (
        <DataTable>
            <TableHeader>
                <TableRow>
                    <TableHead>Holiday</TableHead>
                    <TableHead>Next</TableHead>
                    <TableHead>Type</TableHead>
                    <TableHead>Kept by</TableHead>
                    {canManage && (
                        <TableHead className="w-10">
                            <span className="sr-only">Actions</span>
                        </TableHead>
                    )}
                </TableRow>
            </TableHeader>
            <TableBody>
                {rows.length === 0 && (
                    <EmptyTableRow
                        colSpan={canManage ? 5 : 4}
                        icon={CalendarDays}
                        title="No holidays yet"
                        description="Add the days your company keeps, or put Holy Week and National Heroes Day on the calendar in one go."
                    />
                )}
                {rows.map((holiday) => {
                    const sites = holiday.locations ?? [];

                    return (
                        <TableRow
                            key={holiday.id}
                            {...(canManage
                                ? rowOpens(() => onEdit(holiday))
                                : {})}
                        >
                            <TableCell className="max-w-72">
                                <p className="truncate font-medium">
                                    {holiday.name}
                                </p>
                                <p className="flex items-center gap-1 truncate text-xs text-muted-foreground">
                                    {holiday.recurrence !== 'none' && (
                                        <Repeat className="size-3 shrink-0" />
                                    )}
                                    {holiday.repeats}
                                </p>
                            </TableCell>
                            <TableCell className="whitespace-nowrap">
                                <p
                                    className={cn(
                                        'tabular-nums',
                                        !holiday.next_date &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {formatHolidayDate(holiday)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {holiday.next_date
                                        ? untilLabel(holiday.next_date)
                                        : 'Passed'}
                                </p>
                            </TableCell>
                            <TableCell>
                                <HolidayTypeBadge type={holiday.type} />
                            </TableCell>
                            <TableCell className="max-w-56">
                                {sites.length === 0 ? (
                                    <span className="text-sm text-muted-foreground">
                                        Everyone
                                    </span>
                                ) : (
                                    <span
                                        className="flex items-center gap-1 truncate text-sm"
                                        title={sites
                                            .map((site) => site.name)
                                            .join(', ')}
                                    >
                                        <MapPin className="size-3.5 shrink-0 text-[#0a8b91] dark:text-[#0ABFBF]" />
                                        <span className="truncate">
                                            {sites.length <= 2
                                                ? sites
                                                      .map((site) => site.name)
                                                      .join(', ')
                                                : `${sites[0].name} and ${sites.length - 1} more`}
                                        </span>
                                    </span>
                                )}
                            </TableCell>
                            {canManage && (
                                <TableCell>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <RowMenuTrigger
                                                label={`Actions for ${holiday.name}`}
                                            />
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            className="w-40"
                                        >
                                            <DropdownMenuItem
                                                onSelect={() => onEdit(holiday)}
                                            >
                                                <Pencil className="size-4" />
                                                Edit
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                variant="destructive"
                                                onSelect={() =>
                                                    onArchive(holiday)
                                                }
                                            >
                                                <Archive className="size-4" />
                                                Archive
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
    );
}
