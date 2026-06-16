import { Coffee, LogIn, LogOut, Play } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { AttendanceRecord, AttendanceStatus, PunchType } from './types';

export const DEFAULT_STATUS = 'all';

/** The tabs across the top of the board. */
export const STATUS_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'present', label: 'Present' },
    { value: 'late', label: 'Late' },
    { value: 'undertime', label: 'Undertime' },
    { value: 'absent', label: 'Absent' },
    { value: 'on_leave', label: 'On leave' },
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

/** Format an ISO timestamp as a local clock time, e.g. "8:05 AM". */
export function formatTime(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
}
