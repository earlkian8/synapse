import type {
    DepartmentRef,
    GridEmployee,
    PolicyRef,
    ScheduleRef,
    ShiftSource,
} from '@/features/attendance/types';

// ── The roster (ADR 0037) ────────────────────────────────────────────────────
//
// The plan rather than the record: who is due to work what, day by day, and
// why that shift applies. Configured in Company Setup; Attendance judges each
// day against it.

export type RosterDayHeader = {
    date: string;
    weekday: string;
    day: string;
    is_today: boolean;
    holiday: string | null;
};

export type RosterCell = {
    date: string;
    /** "08:00–17:00", "08:00–12:00 · 17:00–21:00", or "Rest day". */
    label: string;
    type: 'fixed' | 'flexible' | 'hours_only';
    is_working_day: boolean;
    segments: { start: string; end: string }[];
    required_minutes: number;
    schedule_name: string | null;
    source: ShiftSource;
    /** Set when this day carries a one-off override, so it can be cleared. */
    entry_hashid: string | null;
    reason: string | null;
};

export type RosterRow = {
    employee: GridEmployee;
    cells: RosterCell[];
};

export type RosterView = {
    start: string;
    end: string;
    days: RosterDayHeader[];
    rows: RosterRow[];
};

export type RosterFilters = {
    /** Any date in the week on screen. */
    date: string;
    search: string;
    department: number | null;
};

export type RosterPageProps = {
    roster: RosterView;
    options: {
        departments: DepartmentRef[];
        schedules: ScheduleRef[];
        /** What an assignment can single somebody out to be judged by. */
        policies: PolicyRef[];
    };
    can: { manage: boolean };
    filters: RosterFilters;
};
