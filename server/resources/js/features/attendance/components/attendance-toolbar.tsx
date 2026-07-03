import {
    ChevronLeft,
    ChevronRight,
    Download,
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
import { STATUS_FILTERS } from '../constants';
import type { AttendanceFilters, AttendanceTab, DepartmentRef } from '../types';

type Props = {
    filters: AttendanceFilters;
    departments: DepartmentRef[];
    canManage: boolean;
    exportUrl: string;
    onDate: (value: string) => void;
    onSearch: (value: string) => void;
    onStatus: (value: string) => void;
    onDepartment: (value: number | null) => void;
    onManualEntry: () => void;
};

function toIso(d: Date): string {
    return d.toISOString().slice(0, 10);
}

/** Shift the anchor date by one period (day / week / month) in either direction. */
function shiftPeriod(date: string, tab: AttendanceTab, dir: 1 | -1): string {
    const d = new Date(`${date}T00:00:00`);

    if (tab === 'weekly') {
        d.setDate(d.getDate() + 7 * dir);
    } else if (tab === 'monthly') {
        d.setDate(1);
        d.setMonth(d.getMonth() + dir);
    } else {
        d.setDate(d.getDate() + dir);
    }

    return toIso(d);
}

/** The Monday that opens the week containing `date`. */
function weekStart(date: string): string {
    const d = new Date(`${date}T00:00:00`);
    const offset = (d.getDay() + 6) % 7;
    d.setDate(d.getDate() - offset);

    return toIso(d);
}

/** Whether the displayed period already contains (or is after) today. */
function atLatest(date: string, tab: AttendanceTab, today: string): boolean {
    if (tab === 'monthly') {
        return date.slice(0, 7) >= today.slice(0, 7);
    }

    if (tab === 'weekly') {
        return weekStart(date) >= weekStart(today);
    }

    return date >= today;
}

function shortDate(d: Date): string {
    return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

/** The period label shown between the steppers, tuned per tab. */
function periodLabel(date: string, tab: AttendanceTab): string {
    if (tab === 'monthly') {
        return new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
            month: 'long',
            year: 'numeric',
        });
    }

    if (tab === 'weekly') {
        const start = new Date(`${weekStart(date)}T00:00:00`);
        const end = new Date(start);
        end.setDate(start.getDate() + 6);

        return `${shortDate(start)} – ${shortDate(end)}`;
    }

    const today = toIso(new Date());
    const yesterday = shiftPeriod(today, 'today', -1);

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

const RESET_LABEL: Record<AttendanceTab, string> = {
    today: 'Today',
    weekly: 'This week',
    monthly: 'This month',
};

export function AttendanceToolbar({
    filters,
    departments,
    canManage,
    exportUrl,
    onDate,
    onSearch,
    onStatus,
    onDepartment,
    onManualEntry,
}: Props) {
    const [term, setTerm] = useState(filters.search);
    const [syncedSearch, setSyncedSearch] = useState(filters.search);
    const today = toIso(new Date());
    const latest = atLatest(filters.date, filters.tab, today);

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
            {/* Period stepper */}
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-1.5">
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-9"
                        aria-label="Previous period"
                        onClick={() =>
                            onDate(shiftPeriod(filters.date, filters.tab, -1))
                        }
                    >
                        <ChevronLeft className="size-4" />
                    </Button>

                    <div className="relative">
                        <label
                            className="flex h-9 min-w-[9.5rem] cursor-pointer items-center justify-center rounded-md border border-input bg-card px-3 text-sm font-medium shadow-xs transition-colors hover:bg-accent"
                            htmlFor="attendance-date"
                        >
                            {periodLabel(filters.date, filters.tab)}
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
                        aria-label="Next period"
                        disabled={latest}
                        onClick={() =>
                            onDate(shiftPeriod(filters.date, filters.tab, 1))
                        }
                    >
                        <ChevronRight className="size-4" />
                    </Button>

                    {!latest && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="text-muted-foreground"
                            onClick={() => onDate(today)}
                        >
                            {RESET_LABEL[filters.tab]}
                        </Button>
                    )}
                </div>

                <div className="flex items-center gap-2">
                    <Button variant="outline" size="sm" asChild>
                        <a href={exportUrl}>
                            <Download className="size-4" />
                            Export
                        </a>
                    </Button>
                    {canManage && (
                        <Button size="sm" onClick={onManualEntry}>
                            <Plus className="size-4" />
                            Manual entry
                        </Button>
                    )}
                </div>
            </div>

            {/* Filters: search + department (+ status on the daily log) */}
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

                {filters.tab === 'today' && (
                    <Select
                        value={filters.status || 'all'}
                        onValueChange={onStatus}
                    >
                        <SelectTrigger
                            className="w-[150px]"
                            aria-label="Filter by status"
                        >
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            {STATUS_FILTERS.map((option) => (
                                <SelectItem
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}

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
