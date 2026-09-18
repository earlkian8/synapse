import { Coffee, LogIn, LogOut, Play } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type {
    AttendanceFlag,
    AttendanceRecord,
    AttendanceRequestPayload,
    AttendanceRequestStatus,
    AttendanceRequestType,
    AttendanceStatus,
    AttendanceTab,
    PeriodFrequency,
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
    { value: 'half_day', label: 'Half day' },
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
    half_day: 'Half day',
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
    half_day:
        'border-fuchsia-500/30 bg-fuchsia-500/10 text-fuchsia-600 dark:text-fuchsia-400',
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
    half_day: 'bg-fuchsia-500',
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
    half_day:
        'bg-fuchsia-500/15 text-fuchsia-700 dark:text-fuchsia-300 hover:ring-fuchsia-500/40',
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
 * A flag as somebody reading the day would say it (ADR 0038). `late` and
 * `undertime` already have their own minutes on the day, so they are not chips.
 */
export const FLAG_LABELS: Record<AttendanceFlag, string> = {
    late: 'Late',
    undertime: 'Left early',
    half_day: 'Half day',
    late_absent: 'Too late to count',
    below_minimum: 'Too short to count',
    break_deducted: 'Unpunched break deducted',
    break_exceeded: 'Break ran over',
    unapproved_overtime: 'Overtime awaiting approval',
    rest_day_worked: 'Worked a rest day',
    holiday_worked: 'Worked a holiday',
    official_business: 'Official business',
    remote_work: 'Worked remotely',
};

/** Flags worth a chip — the ones the day's minutes do not already say. */
export const CHIP_FLAGS: AttendanceFlag[] = [
    'half_day',
    'late_absent',
    'below_minimum',
    'unapproved_overtime',
    'break_exceeded',
    'break_deducted',
    'rest_day_worked',
    'holiday_worked',
    'official_business',
    'remote_work',
];

/** A flag's chip tone: something to act on, or something to know. */
export const FLAG_TONES: Record<AttendanceFlag, 'warn' | 'info'> = {
    late: 'warn',
    undertime: 'warn',
    half_day: 'warn',
    late_absent: 'warn',
    below_minimum: 'warn',
    break_deducted: 'info',
    break_exceeded: 'warn',
    unapproved_overtime: 'warn',
    rest_day_worked: 'info',
    holiday_worked: 'info',
    official_business: 'info',
    remote_work: 'info',
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
        out.push({
            label: record.flags?.includes('late_absent')
                ? 'Too late to count as present'
                : record.flags?.includes('below_minimum')
                  ? 'Too short to count as present'
                  : 'Unscheduled absence',
            tone: 'danger',
        });
    }

    if (record.status === 'half_day') {
        out.push({ label: 'Judged a half day', tone: 'danger' });
    }

    if (record.flags?.includes('break_exceeded')) {
        out.push({ label: 'Break ran over', tone: 'warn' });
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
    correction: 'Approved correction',
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

// ── Requests (ADR 0039) ──────────────────────────────────────────────────────

/** A request type the way the employee would ask for it. */
export const REQUEST_TYPE_LABELS: Record<AttendanceRequestType, string> = {
    correction: 'Correction',
    overtime: 'Overtime',
    official_business: 'Official business',
    remote_work: 'Remote work',
};

/** What each type is for, in one line — the file dialog's helper text. */
export const REQUEST_TYPE_HINTS: Record<AttendanceRequestType, string> = {
    correction: 'A punch the clock missed or got wrong.',
    overtime: 'Time past your shift that needs approval.',
    official_business: 'Away from the site on company business.',
    remote_work: 'Working somewhere other than the site.',
};

export const REQUEST_STATUS_LABELS: Record<AttendanceRequestStatus, string> = {
    pending: 'Pending',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

/** The inbox's status tabs; pending — the work — first. */
export const REQUEST_STATUS_FILTERS: {
    value: AttendanceRequestStatus | 'all';
    label: string;
}[] = [
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'all', label: 'All' },
];

export const REQUEST_STATUS_STYLES: Record<AttendanceRequestStatus, string> = {
    pending:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    approved:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    rejected:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
    cancelled:
        'border-slate-500/30 bg-slate-500/10 text-slate-600 dark:text-slate-300',
};

/** The four fields a correction can propose, in the order a day happens. */
export const CORRECTION_FIELDS = [
    { key: 'time_in', label: 'Time in', punch: 'clock_in' },
    { key: 'break_start', label: 'Break start', punch: 'break_start' },
    { key: 'break_end', label: 'Break end', punch: 'break_end' },
    { key: 'time_out', label: 'Time out', punch: 'clock_out' },
] as const;

/** "08:00" → "8:00 AM", for a clock-face reading that is not an instant. */
export function formatClockFace(time: string | null | undefined): string {
    if (!time) {
        return '—';
    }

    const [hours, minutes] = time.split(':').map(Number);
    const date = new Date(2000, 0, 1, hours, minutes);

    return date.toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
}

/**
 * What a request asks for, in a line: "Time out 6:00 PM", "2h overtime",
 * "Client site, 9:00 AM – 3:00 PM".
 */
export function describeRequest(
    type: AttendanceRequestType,
    payload: AttendanceRequestPayload,
): string {
    if (type === 'correction') {
        const parts = CORRECTION_FIELDS.filter(({ key }) => payload[key]).map(
            ({ key, label }) => `${label} ${formatClockFace(payload[key])}`,
        );

        return parts.length > 0 ? parts.join(', ') : 'No times given';
    }

    if (type === 'overtime') {
        return `${formatDuration(payload.minutes ?? 0)} overtime${payload.pre_approval ? ', asked in advance' : ''}`;
    }

    const window =
        payload.start_time && payload.end_time
            ? `${formatClockFace(payload.start_time)} – ${formatClockFace(payload.end_time)}`
            : null;

    return (
        [payload.location, window].filter(Boolean).join(', ') ||
        (type === 'official_business'
            ? 'A full working day'
            : 'Punches still required')
    );
}

/** "Sep 14" or "Sep 14 – 16", from calendar dates. */
export function formatDateRange(start: string, end: string): string {
    const format = (date: string, withMonth = true) =>
        new Date(`${date}T00:00:00`).toLocaleDateString(undefined, {
            month: withMonth ? 'short' : undefined,
            day: 'numeric',
        });

    if (start === end) {
        return new Date(`${start}T00:00:00`).toLocaleDateString(undefined, {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
        });
    }

    return start.slice(0, 7) === end.slice(0, 7)
        ? `${format(start)} – ${format(end, false)}`
        : `${format(start)} – ${format(end)}`;
}

// ── Periods (ADR 0039) ───────────────────────────────────────────────────────

export const PERIOD_FREQUENCY_LABELS: Record<PeriodFrequency, string> = {
    weekly: 'Weekly (Mon–Sun)',
    bi_weekly: 'Every two weeks',
    semi_monthly: 'Twice a month (1–15, 16–end)',
    monthly: 'Monthly',
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
