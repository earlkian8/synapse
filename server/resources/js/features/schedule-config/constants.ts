import type {
    Holiday,
    HolidayRecurrence,
    HolidayRule,
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

export const RECURRENCE_OPTIONS: {
    value: HolidayRecurrence;
    label: string;
    hint: string;
}[] = [
    { value: 'none', label: "Doesn't repeat", hint: 'Kept on this date only.' },
    {
        value: 'yearly',
        label: 'Every year on this date',
        hint: "New Year's Day, Christmas, Independence Day.",
    },
    {
        value: 'easter',
        label: 'Every year, counted from Easter',
        hint: 'Holy Week moves with Easter. It is worked out for every year.',
    },
    {
        value: 'weekday',
        label: 'Every year, on a weekday of a month',
        hint: 'National Heroes Day is the last Monday of August.',
    },
];

/** The days of Holy Week and around it, as days from Easter Sunday. */
export const EASTER_PRESETS: { offset: number; label: string }[] = [
    { offset: -46, label: 'Ash Wednesday' },
    { offset: -7, label: 'Palm Sunday' },
    { offset: -3, label: 'Maundy Thursday' },
    { offset: -2, label: 'Good Friday' },
    { offset: -1, label: 'Black Saturday' },
    { offset: 0, label: 'Easter Sunday' },
    { offset: 1, label: 'Easter Monday' },
];

export const WEEK_OPTIONS: { value: number; label: string }[] = [
    { value: 1, label: 'First' },
    { value: 2, label: 'Second' },
    { value: 3, label: 'Third' },
    { value: 4, label: 'Fourth' },
    { value: -1, label: 'Last' },
];

export const WEEKDAY_NAMES = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

export const MONTH_NAMES = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];

/** Easter Sunday in a year (the anonymous Gregorian computation), as the server has it. */
export function easterSunday(year: number): Date {
    const a = year % 19;
    const b = Math.floor(year / 100);
    const c = year % 100;
    const d = Math.floor(b / 4);
    const e = b % 4;
    const f = Math.floor((b + 8) / 25);
    const g = Math.floor((b - f + 1) / 3);
    const h = (19 * a + b - d - g + 15) % 30;
    const i = Math.floor(c / 4);
    const k = c % 4;
    const l = (32 + 2 * e + 2 * i - h - k) % 7;
    const m = Math.floor((a + 11 * h + 22 * l) / 451);
    const month = Math.floor((h + l - 7 * m + 114) / 31);
    const day = ((h + l - 7 * m + 114) % 31) + 1;

    return new Date(year, month - 1, day);
}

/**
 * Where a holiday falls in a year, by its rule — the same arithmetic as the
 * server's `Holiday::occurrenceIn()`, so the form can preview the next dates.
 */
export function occurrenceIn(
    recurrence: HolidayRecurrence,
    rule: HolidayRule | null,
    date: string | null,
    year: number,
): Date | null {
    if (recurrence === 'easter') {
        const easter = easterSunday(year);
        easter.setDate(easter.getDate() + (rule?.offset ?? 0));

        return easter;
    }

    if (recurrence === 'weekday') {
        const month = (rule?.month ?? 1) - 1;
        const weekday = rule?.weekday ?? 1;
        const week = rule?.week ?? 1;

        if (week === -1) {
            const last = new Date(year, month + 1, 0);
            const back = (isoWeekday(last) - weekday + 7) % 7;
            last.setDate(last.getDate() - back);

            return last;
        }

        const first = new Date(year, month, 1);
        const found = new Date(
            year,
            month,
            1 + ((weekday - isoWeekday(first) + 7) % 7) + 7 * (week - 1),
        );

        return found.getMonth() === month ? found : null;
    }

    if (!date) {
        return null;
    }

    const [y, m, d] = date.split('-').map(Number);

    if (recurrence === 'yearly') {
        const found = new Date(year, m - 1, d);

        return found.getMonth() === m - 1 ? found : null;
    }

    return y === year ? new Date(y, m - 1, d) : null;
}

/** The next `count` dates a rule gives, from today. */
export function upcomingDates(
    recurrence: HolidayRecurrence,
    rule: HolidayRule | null,
    date: string | null,
    count = 2,
): Date[] {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const found: Date[] = [];

    for (
        let year = today.getFullYear();
        year <= today.getFullYear() + 5 && found.length < count;
        year++
    ) {
        const next = occurrenceIn(recurrence, rule, date, year);

        if (next && next >= today) {
            found.push(next);
        }
    }

    return found;
}

function isoWeekday(date: Date): number {
    return ((date.getDay() + 6) % 7) + 1;
}

/** "Thu, Apr 2, 2026" from a Date or a "Y-m-d" string. */
export function formatLongDate(value: Date | string): string {
    const date =
        typeof value === 'string' ? new Date(`${value}T00:00:00`) : value;

    return date.toLocaleDateString(undefined, {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

/** "in 12 days", "tomorrow", "today" — how far off a date is. */
export function untilLabel(value: string): string {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const days = Math.round(
        (new Date(`${value}T00:00:00`).getTime() - today.getTime()) /
            MILLISECONDS_PER_DAY,
    );

    if (days === 0) {
        return 'Today';
    }

    if (days === 1) {
        return 'Tomorrow';
    }

    if (days < 45) {
        return `In ${days} days`;
    }

    const months = Math.round(days / 30.4);

    return `In ${months} ${months === 1 ? 'month' : 'months'}`;
}

/** A recurring holiday reads as "Jan 1 · yearly"; a one-off as "Jan 1, 2026". */
export function formatHolidayDate(holiday: Holiday): string {
    if (holiday.next_date) {
        return formatLongDate(holiday.next_date);
    }

    if (!holiday.date || holiday.month === null || holiday.day === null) {
        return '—';
    }

    return `${MONTHS[holiday.month - 1]} ${holiday.day}, ${holiday.date.slice(0, 4)}`;
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
