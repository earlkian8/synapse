import type {
    CaseStatus,
    ClearanceStatus,
    DerivedClearanceStatus,
    EmploymentType,
    OffboardingType,
} from './types';

export const DEFAULT_FILTERS = {
    search: '',
    status: 'active',
    type: null,
    department: null,
} as const;

export const STATUS_FILTERS = [
    { value: 'active', label: 'Active' },
    { value: 'initiated', label: 'Initiated' },
    { value: 'clearance', label: 'In clearance' },
    { value: 'completed', label: 'Completed' },
    { value: 'cancelled', label: 'Cancelled' },
    { value: 'all', label: 'All' },
] as const;

export const CASE_STATUS_LABELS: Record<CaseStatus, string> = {
    initiated: 'Initiated',
    clearance: 'In clearance',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const CASE_STATUS_STYLES: Record<CaseStatus, string> = {
    initiated:
        'border-slate-500/30 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    clearance:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    completed:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    cancelled:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
};

// ── Exit type ────────────────────────────────────────────────────────────────

export const TYPE_LABELS: Record<OffboardingType, string> = {
    resignation: 'Resignation',
    termination: 'Termination',
    retirement: 'Retirement',
    end_of_contract: 'End of contract',
};

export const TYPE_STYLES: Record<OffboardingType, string> = {
    resignation:
        'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
    termination:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
    retirement:
        'border-violet-500/30 bg-violet-500/10 text-violet-600 dark:text-violet-400',
    end_of_contract:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
};

export const TYPE_OPTIONS: { value: OffboardingType; label: string }[] = [
    { value: 'resignation', label: 'Resignation' },
    { value: 'termination', label: 'Termination' },
    { value: 'retirement', label: 'Retirement' },
    { value: 'end_of_contract', label: 'End of contract' },
];

// ── Clearance item status ────────────────────────────────────────────────────

export const CLEARANCE_STATUS_LABELS: Record<ClearanceStatus, string> = {
    pending: 'Pending',
    cleared: 'Cleared',
    flagged: 'Flagged',
};

export const CLEARANCE_STATUS_STYLES: Record<ClearanceStatus, string> = {
    pending:
        'border-slate-500/30 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    cleared:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    flagged:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
};

// ── Derived (case-level) clearance status ────────────────────────────────────

export const DERIVED_CLEARANCE_LABELS: Record<DerivedClearanceStatus, string> =
    {
        pending: 'Clearance pending',
        in_progress: 'Clearance in progress',
        cleared: 'Fully cleared',
    };

export const EMPLOYMENT_TYPE_LABELS: Record<EmploymentType, string> = {
    regular: 'Regular',
    probationary: 'Probationary',
    contractual: 'Contractual',
    part_time: 'Part-time',
};

/** Department label used to group unassigned clearance items. */
export const UNASSIGNED_DEPARTMENT = 'Unassigned';

export function formatDate(date: string | null): string {
    if (!date) {
        return '—';
    }

    return new Date(date).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

export function formatShortDate(date: string): string {
    return new Date(date).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
}
