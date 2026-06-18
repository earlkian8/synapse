import {
    Dumbbell,
    Gift,
    HeartPulse,
    PiggyBank,
    ShieldCheck,
} from 'lucide-react';
import type {
    BenefitCategory,
    BenefitFrequency,
    EnrollmentStatus,
} from './types';

export const CATEGORY_LABELS: Record<BenefitCategory, string> = {
    hmo: 'Health (HMO)',
    insurance: 'Insurance',
    retirement: 'Retirement',
    wellness: 'Wellness',
    other: 'Other',
};

/** Display order for the category sections on the overview. */
export const CATEGORY_ORDER: BenefitCategory[] = [
    'hmo',
    'insurance',
    'retirement',
    'wellness',
    'other',
];

/** Icon + accent colour per category (consistent light/dark tints). */
export const CATEGORY_META: Record<
    BenefitCategory,
    { icon: typeof HeartPulse; accent: string; dot: string }
> = {
    hmo: {
        icon: HeartPulse,
        accent: 'bg-rose-500/10 text-rose-600 dark:text-rose-400',
        dot: 'bg-rose-500',
    },
    insurance: {
        icon: ShieldCheck,
        accent: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
        dot: 'bg-sky-500',
    },
    retirement: {
        icon: PiggyBank,
        accent: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
        dot: 'bg-amber-500',
    },
    wellness: {
        icon: Dumbbell,
        accent: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
        dot: 'bg-emerald-500',
    },
    other: {
        icon: Gift,
        accent: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
        dot: 'bg-violet-500',
    },
};

/** The statutory government benefits tracked in the contributions report. */
export const STATUTORY_LABELS: Record<
    'sss' | 'philhealth' | 'pagibig',
    string
> = {
    sss: 'SSS',
    philhealth: 'PhilHealth',
    pagibig: 'Pag-IBIG',
};

export const FREQUENCY_LABELS: Record<BenefitFrequency, string> = {
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    annual: 'Annual',
    one_time: 'One-time',
};

/** Short per-period suffix, e.g. "/mo". */
export const FREQUENCY_SUFFIX: Record<BenefitFrequency, string> = {
    monthly: '/mo',
    quarterly: '/qtr',
    annual: '/yr',
    one_time: '',
};

export const STATUS_LABELS: Record<EnrollmentStatus, string> = {
    active: 'Active',
    pending: 'Pending',
    waived: 'Waived',
    terminated: 'Terminated',
};

export const STATUS_STYLES: Record<EnrollmentStatus, string> = {
    active: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    pending:
        'border-amber-500/30 bg-amber-500/10 text-amber-600 dark:text-amber-400',
    waived: 'border-slate-500/25 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    terminated:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
};

const PESO = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

/** Format a money amount as Philippine pesos, e.g. "₱1,500.00". */
export function formatPeso(amount: number): string {
    return PESO.format(amount || 0);
}

/** Compact peso for KPI cards, e.g. "₱81.9K". */
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
