import type { HalfDayPeriod, LeaveStatus } from './types';

export const DEFAULT_FILTERS = {
    search: '',
    status: 'pending',
    type: null,
    department: null,
} as const;

/** The tabs across the top of the inbox. `upcoming` is approved leave not yet started. */
export const STATUS_FILTERS = [
    { value: 'pending', label: 'Pending' },
    { value: 'approved', label: 'Approved' },
    { value: 'upcoming', label: 'Upcoming' },
    { value: 'rejected', label: 'Rejected' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'all', label: 'All' },
] as const;

export const STATUS_LABELS: Record<LeaveStatus, string> = {
    pending: 'Pending',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

export const STATUS_STYLES: Record<LeaveStatus, string> = {
    pending:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    approved:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    rejected:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
    cancelled:
        'border-slate-500/30 bg-slate-500/10 text-slate-600 dark:text-slate-300',
};

export const HALF_DAY_LABELS: Record<HalfDayPeriod, string> = {
    morning: 'Morning',
    afternoon: 'Afternoon',
};
