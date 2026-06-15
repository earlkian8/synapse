import {
    ChevronLeft,
    ChevronRight,
    LayoutGrid,
    List,
    Plus,
    Search,
    X,
} from 'lucide-react';
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
import { cn } from '@/lib/utils';
import { STATUS_FILTERS } from '../constants';
import type { AttendanceFilters, DepartmentRef } from '../types';

export type AttendanceView = 'board' | 'list';

type Props = {
    filters: AttendanceFilters;
    departments: DepartmentRef[];
    view: AttendanceView;
    canManage: boolean;
    onDate: (value: string) => void;
    onSearch: (value: string) => void;
    onStatus: (value: string) => void;
    onDepartment: (value: number | null) => void;
    onView: (value: AttendanceView) => void;
    onManualEntry: () => void;
};

/** Shift an ISO date string (YYYY-MM-DD) by a number of days. */
function shiftDate(date: string, days: number): string {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() + days);

    return d.toISOString().slice(0, 10);
}

function prettyDate(date: string): string {
    const today = new Date().toISOString().slice(0, 10);
    const yesterday = shiftDate(today, -1);

    if (date === today) {
        return 'Today';
    }

    if (date === yesterday) {
        return 'Yesterday';
    }

    return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

export function AttendanceToolbar({
    filters,
    departments,
    view,
    canManage,
    onDate,
    onSearch,
    onStatus,
    onDepartment,
    onView,
    onManualEntry,
}: Props) {
    const [term, setTerm] = useState(filters.search);
    const [syncedSearch, setSyncedSearch] = useState(filters.search);
    const today = new Date().toISOString().slice(0, 10);

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
        <div className="flex flex-col gap-3">
            {/* Date stepper + view toggle */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-1.5">
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-9"
                        aria-label="Previous day"
                        onClick={() => onDate(shiftDate(filters.date, -1))}
                    >
                        <ChevronLeft className="size-4" />
                    </Button>

                    <div className="relative">
                        <label
                            className="flex h-9 min-w-[8.5rem] cursor-pointer items-center justify-center rounded-md border border-input bg-card px-3 text-sm font-medium shadow-xs transition-colors hover:bg-accent"
                            htmlFor="attendance-date"
                        >
                            {prettyDate(filters.date)}
                        </label>
                        <input
                            id="attendance-date"
                            type="date"
                            value={filters.date}
                            max={today}
                            onChange={(event) => onDate(event.target.value)}
                            className="absolute inset-0 cursor-pointer opacity-0"
                            aria-label="Pick a date"
                        />
                    </div>

                    <Button
                        variant="outline"
                        size="icon"
                        className="size-9"
                        aria-label="Next day"
                        disabled={filters.date >= today}
                        onClick={() => onDate(shiftDate(filters.date, 1))}
                    >
                        <ChevronRight className="size-4" />
                    </Button>

                    {filters.date !== today && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground"
                            onClick={() => onDate(today)}
                        >
                            Today
                        </Button>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    <div className="flex items-center rounded-md border border-input p-0.5">
                        <button
                            type="button"
                            onClick={() => onView('board')}
                            aria-label="Board view"
                            className={cn(
                                'flex size-7 items-center justify-center rounded transition-colors',
                                view === 'board'
                                    ? 'bg-accent text-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <LayoutGrid className="size-4" />
                        </button>
                        <button
                            type="button"
                            onClick={() => onView('list')}
                            aria-label="List view"
                            className={cn(
                                'flex size-7 items-center justify-center rounded transition-colors',
                                view === 'list'
                                    ? 'bg-accent text-foreground'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            <List className="size-4" />
                        </button>
                    </div>

                    {canManage && (
                        <Button size="sm" onClick={onManualEntry}>
                            <Plus className="size-4" />
                            Manual entry
                        </Button>
                    )}
                </div>
            </div>

            {/* Status tabs */}
            <div className="-mb-px flex items-center gap-1 overflow-x-auto border-b border-border">
                {STATUS_FILTERS.map((tab) => {
                    const active = filters.status === tab.value;

                    return (
                        <button
                            key={tab.value}
                            type="button"
                            onClick={() => onStatus(tab.value)}
                            className={cn(
                                'relative border-b-2 px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                active
                                    ? 'border-[#0ABFBF] text-foreground'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {tab.label}
                        </button>
                    );
                })}
            </div>

            {/* Search + department */}
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
