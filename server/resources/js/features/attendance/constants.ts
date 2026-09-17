import { Coffee, LogIn, LogOut, Play } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type {
    AttendanceRecord,
    AttendanceStatus,
    AttendanceTab,
    PunchSource,
    PunchType,
    ShiftSource,
} from './types';

export const DEFAULT_STATUS = 'all';

/** The tabs across the top of the board. */
export const STATUS_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'present', label: 'Present' },
    { value: 'late', label: 'Late' },
    { value: 'undertime', label: 'Undertime' },
    { value: 'absent', label: 'Absent' },
    { value: 'on_leave', label: 'On leave' },
    { value: 'holiday', label: 'Holiday' },
    { value: 'day_off', label: 'Day off' },
    { value: 'incomplete', label: 'Incomplete' },
] as const;

export const STATUS_LABELS: Record<AttendanceStatus, string> = {
    present: 'Present',
    late: 'Late',
    undertime: 'Undertime',
    absent: 'Absent',
    on_leave: 'On leave',
    day_off: 'Day off',
    holiday: 'Holiday',
    incomplete: 'Incomplete',
};

/** Badge / chip styling per status (border + tint + text), light & dark. */
export const STATUS_STYLES: Record<AttendanceStatus, string> = {
    present:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    late: 'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    undertime:
        'border-orange-500/30 bg-orange-500/10 text-orange-600 dark:text-orange-400',
    absent: 'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
    on_leave:
        'border-[#0ABFBF]/30 bg-[#0ABFBF]/10 text-[#0a9ca3] dark:text-[#0ABFBF]',
    day_off:
        'border-slate-500/25 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    holiday:
        'border-indigo-500/30 bg-indigo-500/10 text-indigo-600 dark:text-indigo-400',
    incomplete:
        'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
};

/** Solid dot colour per status, for the roster strip / legend. */
export const STATUS_DOT: Record<AttendanceStatus, string> = {
    present: 'bg-emerald-500',
    late: 'bg-amber-500',
    undertime: 'bg-orange-500',
    absent: 'bg-rose-500',
    on_leave: 'bg-[#0ABFBF]',
    day_off: 'bg-slate-400',
    holiday: 'bg-indigo-500',
    incomplete: 'bg-sky-500',
};

/**
 * Filled tile styling per status, for the heatmap cells and the weekly grid —
 * a soft tint with a matching hover ring, light & dark.
 */
export const STATUS_TILE: Record<AttendanceStatus, string> = {
    present:
        'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300 hover:ring-emerald-500/40',
    late: 'bg-amber-500/20 text-amber-700 dark:text-amber-300 hover:ring-amber-500/40',
    undertime:
        'bg-orange-500/20 text-orange-700 dark:text-orange-300 hover:ring-orange-500/40',
    absent: 'bg-rose-500/20 text-rose-700 dark:text-rose-300 hover:ring-rose-500/40',
    on_leave:
        'bg-[#0ABFBF]/20 text-[#0a8b91] dark:text-[#0ABFBF] hover:ring-[#0ABFBF]/40',
    day_off:
        'bg-slate-400/15 text-slate-500 dark:text-slate-400 hover:ring-slate-400/40',
    holiday:
        'bg-indigo-500/15 text-indigo-700 dark:text-indigo-300 hover:ring-indigo-500/40',
    incomplete:
        'bg-sky-500/20 text-sky-700 dark:text-sky-300 hover:ring-sky-500/40',
};

/**
 * Why a shift applies, said the way somebody would say it (ADR 0037). The
 * denormalised pointer and a dated assignment mean the same thing to a reader,
 * so they read the same.
 */
export const SHIFT_SOURCE_LABELS: Record<ShiftSource, string> = {
    roster: 'One-off override',
    assignment: 'Assigned shift',
    employee: 'Assigned shift',
    department: 'Department default',
    organization: 'Company default',
    fallback: 'Default hours',
};

/** A one-off override is the only source worth marking on the grid. */
export const SHIFT_SOURCE_IS_OVERRIDE = (source: ShiftSource): boolean =>
    source === 'roster';

/** A late arrival at or over this many minutes is surfaced as an exception. */
export const LATE_EXCEPTION_MINUTES = 30;

export type Anomaly = { label: string; tone: 'warn' | 'danger' };

/**
 * The notable problems with one day's record — drives the table's flag icon and
 * feeds the exceptions panel. Empty when the day is clean.
 */
export function recordAnomalies(record: AttendanceRecord): Anomaly[] {
    const out: Anomaly[] = [];

    if (record.status === 'incomplete') {
        out.push({ label: 'Missing time-out', tone: 'danger' });
    }

    if (record.status === 'absent') {
        out.push({ label: 'Unscheduled absence', tone: 'danger' });
    }

    if (record.late_minutes > 0) {
        out.push({
            label: `Late by ${formatDuration(record.late_minutes)}`,
            tone:
                record.late_minutes >= LATE_EXCEPTION_MINUTES
                    ? 'danger'
                    : 'warn',
        });
    }

    if (record.undertime_minutes > 0) {
        out.push({
            label: `Left ${formatDuration(record.undertime_minutes)} early`,
            tone: 'warn',
        });
    }

    return out;
}

type PunchMeta = {
    label: string;
    verb: string;
    icon: LucideIcon;
    accent: string;
};

/**
 * Where a punch came from, in the words somebody checking a record would use.
 * It is also the answer to "why is there no photo here" — only the mobile app
 * captures one, so a web or biometric punch never having a selfie is normal
 * rather than missing.
 */
export const SOURCE_LABELS: Record<PunchSource, string> = {
    web: 'Web',
    mobile: 'Mobile app',
    kiosk: 'Kiosk',
    biometric: 'Biometric',
    manual: 'Entered by hand',
};

export const PUNCH_META: Record<PunchType, PunchMeta> = {
    clock_in: {
        label: 'Clock in',
        verb: 'Clocked in',
        icon: LogIn,
        accent: 'text-emerald-600 bg-emerald-500/10 dark:text-emerald-400',
    },
    clock_out: {
        label: 'Clock out',
        verb: 'Clocked out',
        icon: LogOut,
        accent: 'text-rose-600 bg-rose-500/10 dark:text-rose-400',
    },
    break_start: {
        label: 'Start break',
        verb: 'Started break',
        icon: Coffee,
        accent: 'text-amber-600 bg-amber-500/10 dark:text-amber-400',
    },
    break_end: {
        label: 'End break',
        verb: 'Back from break',
        icon: Play,
        accent: 'text-sky-600 bg-sky-500/10 dark:text-sky-400',
    },
};

/** Format a minute count as a compact "8h 5m" / "45m" string. */
export function formatDuration(minutes: number): string {
    if (!minutes || minutes <= 0) {
        return '0m';
    }

    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;

    if (hours === 0) {
        return `${mins}m`;
    }

    return mins === 0 ? `${hours}h` : `${hours}h ${mins}m`;
}

/**
 * Format an ISO timestamp as a clock time, e.g. "8:05 AM" — on the given zone's
 * clock, which for attendance is the organisation's (see
 * `useOrganizationTimeZone`), not the browser's.
 */
export function formatTime(iso: string | null, timeZone?: string): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
        timeZone,
    });
}

/** The calendar and clock fields an instant shows in a zone (the browser's when none is given). */
function zonedParts(date: Date, timeZone?: string) {
    const parts = new Intl.DateTimeFormat('en-US', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);

    const part = (type: Intl.DateTimeFormatPartTypes) =>
        parts.find((entry) => entry.type === type)?.value ?? '00';

    return {
        date: `${part('year')}-${part('month')}-${part('day')}`,
        time: `${part('hour') === '24' ? '00' : part('hour')}:${part('minute')}`,
    };
}

/**
 * Today's date ("Y-m-d") on a zone's calendar — the organisation's today, which
 * in the evening in New York is already tomorrow in Manila.
 */
export function todayIn(timeZone?: string): string {
    return zonedParts(new Date(), timeZone).date;
}

/**
 * The 24-hour "HH:MM" an instant reads on a zone's clock — what a time input
 * holds, and what the server reads back as a clock-face time.
 */
export function clockReading(iso: string | null, timeZone?: string): string {
    return iso ? zonedParts(new Date(iso), timeZone).time : '';
}

/** A calendar date as "Y-m-d" from its local fields (never via UTC). */
export function toDateKey(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** The first and last date of the day / week (Mon–Sun) / month a tab shows. */
export function periodRange(
    date: string,
    tab: AttendanceTab,
): { from: string; to: string } {
    const anchor = new Date(`${date}T00:00:00`);

    if (tab === 'monthly') {
        return {
            from: toDateKey(
                new Date(anchor.getFullYear(), anchor.getMonth(), 1),
            ),
            to: toDateKey(
                new Date(anchor.getFullYear(), anchor.getMonth() + 1, 0),
            ),
        };
    }

    if (tab === 'weekly' || tab === 'roster') {
        const start = new Date(anchor);
        start.setDate(anchor.getDate() - ((anchor.getDay() + 6) % 7));
        const end = new Date(start);
        end.setDate(start.getDate() + 6);

        return { from: toDateKey(start), to: toDateKey(end) };
    }

    return { from: date, to: date };
}
