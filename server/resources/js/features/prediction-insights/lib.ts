/**
 * Shared arithmetic and wording for the predictive screens' explanations and charts
 * (Attrition Risk, Promotion Readiness, Performance Forecast — ADR 0098).
 */

/**
 * The two directions a reason can push a score, and how each is drawn. Teal is the
 * favourable side, rose the unfavourable one: a pair checked for colour-blind
 * separation (deuteranopia ΔE 10) in light and dark mode, which the app's
 * emerald/rose tier pair is not. Text never wears these — they colour marks only,
 * and every mark also carries a signed number and an arrow.
 */
export const POLARITY = {
    favourable: {
        fill: 'bg-teal-600',
        svgFill: 'fill-teal-600',
        svgStroke: 'stroke-teal-600',
    },
    unfavourable: {
        fill: 'bg-rose-600 dark:bg-rose-500',
        svgFill: 'fill-rose-600 dark:fill-rose-500',
        svgStroke: 'stroke-rose-600 dark:stroke-rose-500',
    },
} as const;

export type Polarity = keyof typeof POLARITY;

/** A group of marks that share a colour (a tier, a band) — in display order. */
export type ChartGroup<K extends string = string> = {
    key: K;
    label: string;
    /** Tailwind background class for HTML marks. */
    fill: string;
    /** Tailwind fill class for SVG marks. */
    svgFill: string;
};

/** A signed number of points, e.g. "+14", "−3", "+0.5". */
export function formatPoints(points: number, digits = 0): string {
    const rounded = Number(Math.abs(points).toFixed(digits));

    if (rounded === 0) {
        return '±0';
    }

    return `${points >= 0 ? '+' : '−'}${rounded.toFixed(digits)}`;
}

/** A 0–1 share as a whole percentage, e.g. "53%". */
export function formatShare(share: number): string {
    return `${Math.round(share * 100)}%`;
}

/** "3 people" / "1 person". */
export function people(count: number): string {
    return `${count.toLocaleString()} ${count === 1 ? 'person' : 'people'}`;
}

/** Join a list as prose: "a", "a and b", "a, b and c". */
export function joinWords(words: string[]): string {
    if (words.length <= 1) {
        return words[0] ?? '';
    }

    return `${words.slice(0, -1).join(', ')} and ${words.at(-1)}`;
}

/** One row of a by-department breakdown. */
export type GroupRow<K extends string> = {
    key: string;
    label: string;
    count: number;
    segments: Record<K, number>;
    average: number;
};

/**
 * Tally items by a grouping label (a department), each split by its tier or band,
 * with the average score — sorted by that average, highest first. Items without a
 * label are gathered under `fallback`.
 */
export function tallyBy<K extends string>(
    items: { group: string | null; key: K; score: number }[],
    keys: readonly K[],
    fallback = 'No department',
): GroupRow<K>[] {
    const rows = new Map<string, GroupRow<K>>();

    for (const item of items) {
        const label = item.group ?? fallback;
        const row =
            rows.get(label) ??
            ({
                key: label,
                label,
                count: 0,
                segments: Object.fromEntries(keys.map((k) => [k, 0])) as Record<
                    K,
                    number
                >,
                average: 0,
            } satisfies GroupRow<K>);

        row.count += 1;
        row.segments[item.key] += 1;
        row.average += item.score;
        rows.set(label, row);
    }

    return [...rows.values()]
        .map((row) => ({ ...row, average: row.average / row.count }))
        .sort((a, b) => b.average - a.average);
}

/**
 * For a breakdown dialog's `onOpenAutoFocus`: focus the dialog itself rather than its
 * first chart mark, whose tooltip would otherwise open on its own. Tab still walks
 * every mark from there.
 */
export function focusDialogItself(event: Event): void {
    event.preventDefault();
    (event.currentTarget as HTMLElement | null)?.focus();
}
