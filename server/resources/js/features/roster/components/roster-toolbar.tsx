import { ChevronLeft, ChevronRight, Search, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { toDateKey, todayIn } from '@/features/attendance/constants';
import type { DepartmentRef } from '@/features/attendance/types';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import type { RosterFilters } from '../types';

/** The Monday that opens the week containing `date`. */
function weekStart(date: string): string {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() - ((d.getDay() + 6) % 7));

    return toDateKey(d);
}

function shiftWeek(date: string, dir: 1 | -1): string {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() + 7 * dir);

    return toDateKey(d);
}

/** "Sep 22 – Sep 28". */
function weekLabel(date: string): string {
    const start = new Date(`${weekStart(date)}T00:00:00`);
    const end = new Date(start);
    end.setDate(start.getDate() + 6);
    const format = (d: Date) =>
        d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });

    return `${format(start)} – ${format(end)}`;
}

/**
 * The roster's week stepper and filters. The roster reads forward — next week
 * is planned before it happens — so the stepper never stops at today.
 */
export function RosterToolbar({
    filters,
    departments,
    onDate,
    onSearch,
    onDepartment,
}: {
    filters: RosterFilters;
    departments: DepartmentRef[];
    onDate: (value: string) => void;
    onSearch: (value: string) => void;
    onDepartment: (value: number | null) => void;
}) {
    const [term, setTerm] = useState(filters.search);
    const [syncedSearch, setSyncedSearch] = useState(filters.search);
    // "This week" is the organisation's.
    const today = todayIn(useOrganizationTimeZone());

    if (filters.search !== syncedSearch) {
        setSyncedSearch(filters.search);
        setTerm(filters.search);
    }

    useEffect(() => {
        const handle = window.setTimeout(() => {
            if (term !== filters.search) {
                onSearch(term);
            }
        }, 350);

        return () => window.clearTimeout(handle);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            <div className="flex items-center gap-1.5">
                <Button
                    variant="outline"
                    size="icon"
                    className="size-9"
                    aria-label="Previous week"
                    onClick={() => onDate(shiftWeek(filters.date, -1))}
                >
                    <ChevronLeft className="size-4" />
                </Button>

                <div className="relative">
                    <label
                        className="flex h-9 min-w-[9.5rem] cursor-pointer items-center justify-center rounded-md border border-input bg-card px-3 text-sm font-medium shadow-xs transition-colors hover:bg-accent"
                        htmlFor="roster-date"
                    >
                        {weekLabel(filters.date)}
                    </label>
                    <input
                        id="roster-date"
                        type="date"
                        value={filters.date}
                        onChange={(event) => onDate(event.target.value)}
                        className="absolute inset-0 cursor-pointer opacity-0"
                        aria-label="Pick a week"
                    />
                </div>

                <Button
                    variant="outline"
                    size="icon"
                    className="size-9"
                    aria-label="Next week"
                    onClick={() => onDate(shiftWeek(filters.date, 1))}
                >
                    <ChevronRight className="size-4" />
                </Button>

                {weekStart(filters.date) !== weekStart(today) && (
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-muted-foreground"
                        onClick={() => onDate(today)}
                    >
                        This week
                    </Button>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-64">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={term}
                        onChange={(event) => setTerm(event.target.value)}
                        placeholder="Search by name or no.…"
                        className="pl-9"
                        aria-label="Search employees"
                    />
                    {term && (
                        <button
                            type="button"
                            onClick={() => setTerm('')}
                            className="absolute top-1/2 right-2 -translate-y-1/2 rounded-sm p-0.5 text-muted-foreground hover:text-foreground"
                            aria-label="Clear search"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>

                <Select
                    value={
                        filters.department ? String(filters.department) : 'all'
                    }
                    onValueChange={(value) =>
                        onDepartment(value === 'all' ? null : Number(value))
                    }
                >
                    <SelectTrigger
                        className="w-[170px]"
                        aria-label="Filter by department"
                    >
                        <SelectValue placeholder="Department" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All departments</SelectItem>
                        {departments.map((department) => (
                            <SelectItem
                                key={department.id}
                                value={String(department.id)}
                            >
                                {department.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
        </div>
    );
}
