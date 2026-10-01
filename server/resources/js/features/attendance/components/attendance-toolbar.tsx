import { ChevronLeft, ChevronRight, Download, Plus } from 'lucide-react';
import {
    FilterSelect,
    ListToolbar,
    SearchInput,
} from '@/components/data-table';
import { Button } from '@/components/ui/button';
import { useOrganizationTimeZone } from '@/hooks/use-organization-time-zone';
import {
    DEFAULT_STATUS,
    STATUS_FILTERS,
    toDateKey,
    todayIn,
} from '../constants';
import type { AttendanceFilters, AttendanceTab, DepartmentRef } from '../types';

type Props = {
    filters: AttendanceFilters;
    departments: DepartmentRef[];
    canManage: boolean;
    exportUrl: string;
    /** The payroll period summary — offered on the monthly tab only. */
    periodExportUrl?: string;
    onDate: (value: string) => void;
    onSearch: (value: string) => void;
    onStatus: (value: string) => void;
    onDepartment: (value: number | null) => void;
    /** Clear search, status and department together — one request. */
    onReset: () => void;
    onManualEntry: () => void;
};

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

    return toDateKey(d);
}

/** The Monday that opens the week containing `date`. */
function weekStart(date: string): string {
    const d = new Date(`${date}T00:00:00`);
    const offset = (d.getDay() + 6) % 7;
    d.setDate(d.getDate() - offset);

    return toDateKey(d);
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
function periodLabel(date: string, tab: AttendanceTab, today: string): string {
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

/**
 * The row above the attendance tables: the period stepper, search and filters
 * on the left, the exports and manual entry on the right.
 */
export function AttendanceToolbar({
    filters,
    departments,
    canManage,
    exportUrl,
    periodExportUrl,
    onDate,
    onSearch,
    onStatus,
    onDepartment,
    onReset,
    onManualEntry,
}: Props) {
    // "Today" is the organisation's, the same day the board opens on.
    const today = todayIn(useOrganizationTimeZone());
    const latest = atLatest(filters.date, filters.tab, today);

    const filtered =
        filters.search !== '' ||
        filters.department !== null ||
        (filters.tab === 'today' && filters.status !== DEFAULT_STATUS);

    return (
        <ListToolbar
            filtered={filtered}
            onReset={onReset}
            actions={
                <>
                    <Button variant="outline" size="sm" asChild>
                        <a href={exportUrl}>
                            <Download className="size-4" />
                            Export
                        </a>
                    </Button>
                    {periodExportUrl && (
                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={periodExportUrl}
                                title="One row per employee for the month: days worked, absences, half days, and every minute bucket — regular, overtime, approved overtime, night, rest day, holiday."
                            >
                                <Download className="size-4" />
                                Payroll summary
                            </a>
                        </Button>
                    )}
                    {canManage && (
                        <Button size="sm" onClick={onManualEntry}>
                            <Plus className="size-4" />
                            Manual entry
                        </Button>
                    )}
                </>
            }
        >
            {/* Period stepper */}
            <div className="flex items-center gap-1">
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
                        className="flex h-9 min-w-36 cursor-pointer items-center justify-center rounded-md border border-input bg-card px-3 text-sm font-medium shadow-xs transition-colors hover:bg-accent"
                        htmlFor="attendance-date"
                    >
                        {periodLabel(filters.date, filters.tab, today)}
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

                {filters.date !== today && (
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

            <SearchInput
                value={filters.search}
                onSearch={onSearch}
                placeholder="Search by name or no.…"
                label="Search employees"
            />

            {filters.tab === 'today' && (
                <FilterSelect
                    label="Filter by status"
                    value={filters.status || DEFAULT_STATUS}
                    onChange={onStatus}
                    // "Not clocked in yet" is live: it only means something
                    // for today.
                    options={STATUS_FILTERS.filter(
                        (option) =>
                            option.value !== 'not_clocked_in' ||
                            filters.date === today,
                    )}
                    className="w-44"
                />
            )}

            <FilterSelect
                label="Filter by department"
                value={filters.department ? String(filters.department) : 'all'}
                onChange={(value) =>
                    onDepartment(value === 'all' ? null : Number(value))
                }
                options={[
                    { value: 'all', label: 'All departments' },
                    ...departments.map((department) => ({
                        value: String(department.id),
                        label: department.name,
                    })),
                ]}
                className="w-44"
            />
        </ListToolbar>
    );
}
