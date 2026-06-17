import type { PayrollStatus, PayslipStatus } from './types';

/** The status tabs across the top of the runs overview. */
export const STATUS_FILTERS = [
    { value: 'all', label: 'All' },
    { value: 'draft', label: 'Draft' },
    { value: 'processing', label: 'Processing' },
    { value: 'finalized', label: 'Finalized' },
    { value: 'paid', label: 'Paid' },
] as const;

export const PERIOD_STATUS_LABELS: Record<PayrollStatus, string> = {
    draft: 'Draft',
    processing: 'Processing',
    finalized: 'Finalized',
    paid: 'Paid',
};

/** Badge styling per run status (border + tint + text), light & dark. */
export const PERIOD_STATUS_STYLES: Record<PayrollStatus, string> = {
    draft: 'border-slate-500/25 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    processing:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    finalized: 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
    paid: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
};

export const PAYSLIP_STATUS_LABELS: Record<PayslipStatus, string> = {
    draft: 'Draft',
    released: 'Released',
};

export const PAYSLIP_STATUS_STYLES: Record<PayslipStatus, string> = {
    draft: 'border-slate-500/25 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    released:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
};

const PESO = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/** Format a money amount as Philippine pesos, e.g. "₱41,854.25". */
export function formatPeso(amount: number): string {
    return PESO.format(amount || 0);
}

/** Compact peso for KPI cards, e.g. "₱1.2M" / "₱41.9K". */
export function formatPesoCompact(amount: number): string {
    const value = amount || 0;

    if (Math.abs(value) >= 1_000_000) {
        return `₱${(value / 1_000_000).toFixed(1)}M`;
    }

    if (Math.abs(value) >= 1_000) {
        return `₱${(value / 1_000).toFixed(1)}K`;
    }

    return `₱${value.toFixed(0)}`;
}

/** Format an ISO date (YYYY-MM-DD) as "Jun 16, 2026". */
export function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

/** A compact date range, e.g. "Jun 1 – 15, 2026". */
export function formatRange(start: string, end: string): string {
    const s = new Date(`${start}T00:00:00`);
    const e = new Date(`${end}T00:00:00`);
    const sameMonth =
        s.getMonth() === e.getMonth() && s.getFullYear() === e.getFullYear();

    const startLabel = s.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
    });
    const endLabel = e.toLocaleDateString(undefined, {
        month: sameMonth ? undefined : 'short',
        day: 'numeric',
        year: 'numeric',
    });

    return `${startLabel} – ${endLabel}`;
}
