import type {
    AttendanceStatus,
    CertificateStanding,
    CostCategory,
    EnrollmentMode,
    ProgramStatus,
    TrainingEnrollmentStatus,
} from './types';

export const PROGRAM_STATUS_LABELS: Record<ProgramStatus, string> = {
    upcoming: 'Upcoming',
    ongoing: 'Ongoing',
    completed: 'Completed',
};

export const PROGRAM_STATUS_STYLES: Record<ProgramStatus, string> = {
    upcoming: 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
    ongoing:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    completed: 'border-border bg-muted text-muted-foreground',
};

/** Display order for the program sections on the overview. */
export const PROGRAM_STATUS_ORDER: ProgramStatus[] = [
    'ongoing',
    'upcoming',
    'completed',
];

export const ENROLLMENT_STATUS_LABELS: Record<
    TrainingEnrollmentStatus,
    string
> = {
    requested: 'Requested',
    enrolled: 'Enrolled',
    completed: 'Completed',
    dropped: 'Dropped',
    declined: 'Declined',
};

export const ENROLLMENT_STATUS_STYLES: Record<
    TrainingEnrollmentStatus,
    string
> = {
    enrolled: 'border-sky-500/30 bg-sky-500/10 text-sky-600 dark:text-sky-400',
    completed:
        'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    dropped:
        'border-slate-500/25 bg-slate-500/10 text-slate-600 dark:text-slate-300',
    requested:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    declined:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
};

/** Who may join a program, in the words the form and the catalogue use. */
export const ENROLLMENT_MODE_LABELS: Record<EnrollmentMode, string> = {
    closed: 'HR enrolls people',
    open: 'Anyone can join',
    approval: 'Anyone can ask to join',
};

export const ENROLLMENT_MODE_HINTS: Record<EnrollmentMode, string> = {
    closed: 'Only HR adds people to the roster.',
    open: 'Employees take a seat themselves while seats last.',
    approval: 'Employees ask; HR approves or declines each request.',
};

export const ATTENDANCE_LABELS: Record<AttendanceStatus, string> = {
    present: 'Present',
    late: 'Late',
    absent: 'Absent',
    excused: 'Excused',
};

/** Tones for an attendance mark: attended in green, late in amber. */
export const ATTENDANCE_STYLES: Record<AttendanceStatus, string> = {
    present:
        'border-emerald-500/40 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    late: 'border-amber-500/40 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    absent: 'border-rose-500/40 bg-rose-500/10 text-rose-600 dark:text-rose-400',
    excused: 'border-border bg-muted text-muted-foreground',
};

export const ATTENDANCE_ORDER: AttendanceStatus[] = [
    'present',
    'late',
    'absent',
    'excused',
];

export const COST_CATEGORY_LABELS: Record<CostCategory, string> = {
    trainer_fee: 'Trainer fee',
    venue: 'Venue',
    materials: 'Materials',
    travel: 'Travel',
    meals: 'Meals',
    certification: 'Certification',
    other: 'Other',
};

export const COST_CATEGORY_ORDER: CostCategory[] = [
    'trainer_fee',
    'venue',
    'materials',
    'travel',
    'meals',
    'certification',
    'other',
];

export const STANDING_LABELS: Record<CertificateStanding, string> = {
    valid: 'Valid',
    expiring: 'Expiring soon',
    expired: 'Expired',
    renewed: 'Renewed',
};

export const STANDING_STYLES: Record<CertificateStanding, string> = {
    valid: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    expiring:
        'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    expired:
        'border-rose-500/30 bg-rose-500/10 text-rose-600 dark:text-rose-400',
    renewed: 'border-border bg-muted text-muted-foreground',
};

/** A multi-line field, styled as the app's other text areas. */
export const TEXTAREA =
    'flex w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-colors placeholder:text-muted-foreground focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none aria-invalid:border-destructive';

/** Money in pesos, as the rest of the app shows it: "₱12,500" or "₱12,500.50". */
export function formatMoney(amount: number | null): string {
    if (amount === null) {
        return '—';
    }

    return `₱${amount.toLocaleString(undefined, {
        minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
        maximumFractionDigits: 2,
    })}`;
}

/** A wall-clock "13:30" as "1:30 PM". */
export function formatClock(time: string): string {
    const [h, m] = time.split(':').map(Number);

    return new Date(2000, 0, 1, h, m).toLocaleTimeString(undefined, {
        hour: 'numeric',
        minute: '2-digit',
    });
}

/** A session's times, "9:00 AM – 5:00 PM". */
export function formatSessionTime(start: string, end: string): string {
    return `${formatClock(start)} – ${formatClock(end)}`;
}

/** How long a certificate stays valid, in words. */
export function validityLabel(months: number | null): string {
    if (months === null) {
        return 'never expires';
    }

    if (months % 12 === 0) {
        const years = months / 12;

        return `valid ${years} ${years === 1 ? 'year' : 'years'}`;
    }

    return `valid ${months} ${months === 1 ? 'month' : 'months'}`;
}

/** Today's date on this device, "YYYY-MM-DD". */
export function todayIso(): string {
    const now = new Date();
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** Format a completion score out of 100, e.g. "85%". */
export function formatScore(score: number | null): string {
    return score === null
        ? '—'
        : `${Number.isInteger(score) ? score : score.toFixed(1)}%`;
}

/** Colour band for a completion score, from rose (low) to emerald (high). */
export function scoreTone(score: number | null): string {
    if (score === null) {
        return 'text-muted-foreground';
    }

    if (score >= 85) {
        return 'text-emerald-600 dark:text-emerald-400';
    }

    if (score >= 70) {
        return 'text-[#0a8b91] dark:text-[#0ABFBF]';
    }

    if (score >= 50) {
        return 'text-amber-600 dark:text-amber-400';
    }

    return 'text-rose-600 dark:text-rose-400';
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

/** A human date window, e.g. "Jun 15 – 19, 2026", "From Jul 6, 2026", or "Self-paced". */
export function formatDateRange(
    start: string | null,
    end: string | null,
): string {
    if (!start && !end) {
        return 'Self-paced';
    }

    if (start && !end) {
        return `From ${formatDate(start)}`;
    }

    if (!start && end) {
        return `Until ${formatDate(end)}`;
    }

    return `${formatDate(start)} – ${formatDate(end)}`;
}
