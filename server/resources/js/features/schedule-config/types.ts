export type WeekDay = 'Mon' | 'Tue' | 'Wed' | 'Thu' | 'Fri' | 'Sat' | 'Sun';

/**
 * How a day on a schedule is judged (ADR 0037):
 * - `fixed` — against the shift's own start and end.
 * - `flexible` — against the core window it must cover.
 * - `hours_only` — against the hours alone; never late.
 */
export type ScheduleType = 'fixed' | 'flexible' | 'hours_only';

/** One stretch of hours. An end at or before the start crosses midnight. */
export type ShiftSegment = {
    start: string; // "HH:MM"
    end: string; // "HH:MM"
};

/** One day of a schedule's cycle. For a week, `day_index` 1 is Monday. */
export type ScheduleDay = {
    day_index: number;
    is_rest_day: boolean;
    segments: ShiftSegment[];
    required_minutes: number;
    core_start: string | null;
    core_end: string | null;
    earliest_start: string | null;
    latest_end: string | null;
    unpaid_break_minutes: number;
};

export type WorkSchedule = {
    id: number;
    hashid: string;
    name: string;
    type: ScheduleType;
    /** 7 for a weekly pattern; anything else is a rotation. */
    cycle_length_days: number;
    /** Which date day 1 of a rotation falls on. */
    cycle_anchor_date: string | null; // "Y-m-d"
    weekly_required_minutes: number | null;
    grace_minutes: number;
    /** The attendance policy days on this schedule are judged by (ADR 0038). */
    attendance_policy_id: number | null;

    /** The pattern's summary, derived server-side. Read-only here. */
    start_time: string | null; // "HH:MM"
    end_time: string | null; // "HH:MM"
    work_days: WeekDay[];
    required_hours: number;

    days: ScheduleDay[];
    employees_count: number;
};

export type HolidayType = 'regular' | 'special_non_working' | 'special_working';

/**
 * How a holiday repeats (ADR 0078): not at all, on the same date every year, a
 * number of days from Easter Sunday, or on the n-th weekday of a month.
 */
export type HolidayRecurrence = 'none' | 'yearly' | 'easter' | 'weekday';

/** A movable holiday's numbers. `week` −1 is the last; `weekday` 1 is Monday. */
export type HolidayRule = {
    offset?: number;
    month?: number;
    weekday?: number;
    week?: number;
};

/** A site, as a local holiday names it. */
export type HolidaySite = { id: number; name: string };

export type Holiday = {
    id: number;
    hashid: string;
    name: string;
    date: string | null; // "Y-m-d"
    type: HolidayType;
    is_recurring: boolean;
    recurrence: HolidayRecurrence;
    rule: HolidayRule | null;
    /** How it repeats, in words: "Every year, 3 days before Easter". */
    repeats: string;
    month: number | null;
    day: number | null;
    /** The next date it falls on; null for a one-off that has passed. */
    next_date: string | null;
    /** The sites a local holiday is kept at; empty = everywhere. */
    locations?: HolidaySite[];
};

export type SchedulePermissions = {
    manage: boolean;
};

export type ScheduleSetupPageProps = {
    schedules: WorkSchedule[];
    archivedSchedules: WorkSchedule[];
    holidays: Holiday[];
    archivedHolidays: Holiday[];
    /** The schedule anybody with no assignment falls back to. */
    defaultScheduleId: number | null;
    /** The attendance policies a schedule can be judged by. */
    policies: { id: number; name: string; is_default: boolean }[];
    /** The active sites a local holiday can be kept at. */
    locations: HolidaySite[];
    can: SchedulePermissions;
};
