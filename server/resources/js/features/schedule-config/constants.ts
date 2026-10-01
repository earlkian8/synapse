import type {
    Holiday,
    HolidayType,
    ScheduleDay,
    ScheduleType,
    ShiftSegment,
    WeekDay,
    WorkSchedule,
} from './types';

export const WEEK_DAYS: WeekDay[] = [
    'Mon',
    'Tue',
    'Wed',
    'Thu',
    'Fri',
    'Sat',
    'Sun',
];

export const DEFAULT_WORK_DAYS: WeekDay[] = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

/** Days in a week — the cycle length that means "a plain week", not a rotation. */
export const WEEK_LENGTH = 7;

/** The longest rotation a schedule may run over (twelve weeks). */
export const MAX_CYCLE_LENGTH = 84;

const MILLISECONDS_PER_DAY = 86_400_000;

/** The hours a new working day starts with. */
export const DEFAULT_SEGMENT: ShiftSegment = { start: '08:00', end: '17:00' };

/** Eight hours — what a day requires when nothing says otherwise. */
export const DEFAULT_REQUIRED_MINUTES = 480;

export const SCHEDULE_TYPE_OPTIONS: {
    value: ScheduleType;
    label: string;
    hint: string;
}[] = [
    {
        value: 'fixed',
        label: 'Fixed hours',
        hint: 'Late against the start, short against the end.',
    },
    {
        value: 'flexible',
        label: 'Flexible hours',
        hint: 'Late only after the core window opens.',
    },
    {
        value: 'hours_only',
        label: 'Hours only',
        hint: 'Never late — only the hours count.',
    },
];

export const SCHEDULE_TYPE_LABELS: Record<ScheduleType, string> = {
    fixed: 'Fixed',
    flexible: 'Flexible',
    hours_only: 'Hours only',
};

export const HOLIDAY_TYPE_OPTIONS: { value: HolidayType; label: string }[] = [
    { value: 'regular', label: 'Regular holiday' },
    { value: 'special_non_working', label: 'Special (non-working)' },
    { value: 'special_working', label: 'Special (working)' },
];

export const HOLIDAY_TYPE_LABELS: Record<HolidayType, string> = {
    regular: 'Regular',
    special_non_working: 'Special non-working',
    special_working: 'Special working',
};

export const HOLIDAY_TYPE_STYLES: Record<HolidayType, string> = {
    regular:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
    special_non_working:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    special_working:
        'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
};

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

/** A recurring holiday reads as "Jan 1 · yearly"; a one-off as "Jan 1, 2026". */
export function formatHolidayDate(holiday: Holiday): string {
    if (!holiday.date || holiday.month === null || holiday.day === null) {
        return '—';
    }

    const base = `${MONTHS[holiday.month - 1]} ${holiday.day}`;

    if (holiday.is_recurring) {
        return `${base} · yearly`;
    }

    return `${base}, ${holiday.date.slice(0, 4)}`;
}

/** A short human description of a schedule's hours and working days. */
export function formatScheduleHours(schedule: WorkSchedule): string {
    if (isRotation(schedule)) {
        const working = schedule.days.filter((day) => !day.is_rest_day).length;

        return `${working} on, ${schedule.cycle_length_days - working} off · ${schedule.cycle_length_days}-day cycle`;
    }

    const first = schedule.days.find((day) => !day.is_rest_day);
    const hours = first ? formatSegments(first.segments) : null;

    const days =
        schedule.work_days.length > 0
            ? summariseDays(schedule.work_days)
            : 'No working days';

    return `${hours ?? 'Hours only'} · ${days}`;
}

/** Whether a schedule runs on a rotation rather than a plain week. */
export function isRotation(schedule: { cycle_length_days: number }): boolean {
    return schedule.cycle_length_days !== WEEK_LENGTH;
}

/** "08:00–12:00 · 17:00–21:00", or null when there are no hours. */
export function formatSegments(segments: ShiftSegment[]): string | null {
    if (segments.length === 0) {
        return null;
    }

    return segments
        .map((segment) => `${segment.start}–${segment.end}`)
        .join(' · ');
}

/** "Mon–Fri" where the days run consecutively, "Mon, Wed, Fri" where they do not. */
export function summariseDays(days: WeekDay[]): string {
    const ordered = WEEK_DAYS.filter((day) => days.includes(day));

    if (ordered.length === 0) {
        return 'No working days';
    }

    if (ordered.length === WEEK_LENGTH) {
        return 'Every day';
    }

    const indices = ordered.map((day) => WEEK_DAYS.indexOf(day));
    const consecutive = indices.every(
        (value, index) => index === 0 || value === indices[index - 1] + 1,
    );

    return consecutive && ordered.length > 2
        ? `${ordered[0]}–${ordered[ordered.length - 1]}`
        : ordered.join(', ');
}

/** "8h" / "7h 30m" — what a day asks for. */
export function formatMinutes(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    if (hours === 0) {
        return `${rest}m`;
    }

    return rest === 0 ? `${hours}h` : `${hours}h ${rest}m`;
}

/** How long a list of segments runs, in minutes, counting past midnight. */
export function segmentMinutes(segments: ShiftSegment[]): number {
    return segments.reduce((total, segment) => {
        const start = minuteOfDay(segment.start);
        const end = minuteOfDay(segment.end);

        return total + (end <= start ? 1440 - start + end : end - start);
    }, 0);
}

function minuteOfDay(time: string): number {
    const [hours, minutes] = time.split(':').map(Number);

    return (hours || 0) * 60 + (minutes || 0);
}

/**
 * Which day of a schedule's cycle a date is — the same rule the server's
 * ShiftResolver applies, so the editor's preview is what will actually be saved.
 *
 * A weekly pattern indexes by weekday (1 = Monday); a rotation counts days from
 * its anchor. Returns 1..cycleLength.
 */
export function cycleDayIndex(
    date: Date,
    cycleLength: number,
    anchor: string | null,
): number {
    if (cycleLength === WEEK_LENGTH && !anchor) {
        // Date#getDay is 0 for Sunday; the cycle counts Monday as 1.
        return ((date.getDay() + 6) % 7) + 1;
    }

    const from = anchor ? parseDate(anchor) : startOfWeek(date);
    const elapsed = Math.round(
        (date.getTime() - from.getTime()) / MILLISECONDS_PER_DAY,
    );

    return (((elapsed % cycleLength) + cycleLength) % cycleLength) + 1;
}

/** One day of the editor's preview. */
export type PreviewDay = {
    date: Date;
    label: string;
    isRestDay: boolean;
    isToday: boolean;
};

/**
 * The next `count` days as the pattern would judge them — rendered from the
 * form's current state, so a pattern is checked before it is saved.
 */
export function previewDays(
    days: ScheduleDay[],
    type: ScheduleType,
    cycleLength: number,
    anchor: string | null,
    count = 14,
): PreviewDay[] {
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    return Array.from({ length: count }, (_, offset) => {
        const date = new Date(today);
        date.setDate(today.getDate() + offset);

        const day = days[cycleDayIndex(date, cycleLength, anchor) - 1];
        const isRestDay = !day || day.is_rest_day || day.segments.length === 0;

        return {
            date,
            isToday: offset === 0,
            isRestDay,
            label: isRestDay
                ? 'Rest'
                : type === 'hours_only'
                  ? formatMinutes(day.required_minutes)
                  : (formatSegments(day.segments) ?? 'Rest'),
        };
    });
}

/** A blank day row for a cycle that has just grown. */
export function blankDay(dayIndex: number, isRestDay = false): ScheduleDay {
    return {
        day_index: dayIndex,
        is_rest_day: isRestDay,
        segments: isRestDay ? [] : [{ ...DEFAULT_SEGMENT }],
        required_minutes: isRestDay ? 0 : DEFAULT_REQUIRED_MINUTES,
        core_start: null,
        core_end: null,
        earliest_start: null,
        latest_end: null,
        unpaid_break_minutes: 0,
    };
}

/** The seven-day Mon–Fri pattern a new schedule starts from. */
export function defaultPattern(): ScheduleDay[] {
    return WEEK_DAYS.map((_, index) => blankDay(index + 1, index >= 5));
}

/** What the day at `dayIndex` is called — a weekday, or "Day 3" in a rotation. */
export function dayLabel(dayIndex: number, cycleLength: number): string {
    return cycleLength === WEEK_LENGTH
        ? WEEK_DAYS[dayIndex - 1]
        : `Day ${dayIndex}`;
}

function parseDate(value: string): Date {
    const date = new Date(`${value}T00:00:00`);
    date.setHours(0, 0, 0, 0);

    return date;
}

function startOfWeek(date: Date): Date {
    const start = new Date(date);
    start.setDate(date.getDate() - ((date.getDay() + 6) % 7));
    start.setHours(0, 0, 0, 0);

    return start;
}
