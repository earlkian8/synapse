import type { NominationBand, NominationSignalKey } from './types';

/** Brand teal — the fallback accent when an award type has no colour. */
export const DEFAULT_AWARD_COLOR = '#0ABFBF';

/** Preset accent colours offered when creating an award type. */
export const AWARD_COLOR_PRESETS = [
    '#0ABFBF', // teal (brand)
    '#f59e0b', // amber
    '#10b981', // emerald
    '#3b82f6', // blue
    '#8b5cf6', // violet
    '#ec4899', // pink
    '#ef4444', // red
    '#64748b', // slate
] as const;

/**
 * Inline pill styles tinted by an award type's colour (a hex string). Falls back
 * to the brand accent. Uses 8-digit hex alpha so it works with any preset.
 */
export function awardColorStyle(color: string | null): React.CSSProperties {
    const c = color ?? DEFAULT_AWARD_COLOR;

    return {
        color: c,
        backgroundColor: `${c}1a`,
        borderColor: `${c}55`,
    };
}

// ── Nomination board ─────────────────────────────────────────────────────────

/**
 * The fixed hue of each nomination signal — segments of the contribution bar
 * and dots in the breakdown always use these, so a signal is recognisable
 * across every award type.
 */
export const SIGNAL_COLORS: Record<NominationSignalKey, string> = {
    performance: '#0ABFBF', // teal (brand)
    forecast: '#ec4899', // pink
    attendance: '#10b981', // emerald
    training: '#8b5cf6', // violet
    tenure: '#3b82f6', // blue
    gap: '#f59e0b', // amber
};

export const BAND_LABELS: Record<NominationBand, string> = {
    strong: 'Strong fit',
    promising: 'Promising',
    fair: 'Fair',
    weak: 'Weak',
};

/** Text tone for a nominee's fit score, by band. */
export const BAND_TONES: Record<NominationBand, string> = {
    strong: 'text-emerald-600 dark:text-emerald-400',
    promising: 'text-[#0a8b91] dark:text-[#0ABFBF]',
    fair: 'text-amber-600 dark:text-amber-400',
    weak: 'text-rose-600 dark:text-rose-400',
};

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
