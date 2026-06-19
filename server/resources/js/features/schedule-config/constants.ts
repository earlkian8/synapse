import type { Holiday, HolidayType, WeekDay, WorkSchedule } from './types';

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
    const time =
        schedule.start_time && schedule.end_time
            ? `${schedule.start_time}–${schedule.end_time}`
            : 'Flexible hours';

    const days =
        schedule.work_days.length > 0
            ? schedule.work_days.join(' · ')
            : 'Mon–Fri';

    return `${time} · ${days}`;
}
