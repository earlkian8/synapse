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

/**
 * For a breakdown dialog's `onOpenAutoFocus`: focus the dialog itself rather than its
 * first chart mark, whose tooltip would otherwise open on its own. Tab still walks
 * every mark from there.
 */
export function focusDialogItself(event: Event): void {
    event.preventDefault();
    (event.currentTarget as HTMLElement | null)?.focus();
}

/** A date as the breakdowns write it: "Oct 3" this year, "Oct 3, 2025" before. */
export function shortDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);
    const sameYear = date.getFullYear() === new Date().getFullYear();

    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        ...(sameYear ? {} : { year: 'numeric' }),
    });
}

/** When a result was produced, e.g. "as of Oct 3". */
export function formatWhen(iso: string | null): string {
    return iso ? `as of ${shortDate(iso)}` : '';
}

/** The middle value of a list (0 for an empty one). */
export function median(values: number[]): number {
    if (values.length === 0) {
        return 0;
    }

    const sorted = [...values].sort((a, b) => a - b);
    const mid = Math.floor(sorted.length / 2);

    return sorted.length % 2
        ? sorted[mid]
        : (sorted[mid - 1] + sorted[mid]) / 2;
}

/**
 * How a score moved since the person's previous run, when there is one: the points
 * and the date of that run.
 */
export function sinceLastRun(
    history: { score: number; at: string | null }[],
): { points: number; since: string | null } | null {
    if (history.length < 2) {
        return null;
    }

    const [previous, latest] = history.slice(-2);

    return { points: latest.score - previous.score, since: previous.at };
}

/**
 * The chance a next rating reaches `threshold`, reading a forecast's range as a
 * normal spread around it (the range holds `coverage` of outcomes). A rough guide
 * that joins two models, so the page calls it "about".
 */
export function chanceOfReaching(
    forecast: number,
    low: number,
    high: number,
    threshold: number,
    coverage = 0.8,
): number {
    // z for the two-sided coverage (0.8 → 1.2816).
    const z = inverseNormal(0.5 + coverage / 2);
    const sd = Math.max(0.5, (high - low) / (2 * z));

    return 1 - normalCdf((threshold - forecast) / sd);
}

function normalCdf(x: number): number {
    // Abramowitz & Stegun 7.1.26, accurate to ~1e-7.
    const t = 1 / (1 + (0.3275911 * Math.abs(x)) / Math.SQRT2);
    const y =
        1 -
        ((((1.061405429 * t - 1.453152027) * t + 1.421413741) * t -
            0.284496736) *
            t +
            0.254829592) *
            t *
            Math.exp(-(x * x) / 2);

    return x >= 0 ? (1 + y) / 2 : (1 - y) / 2;
}

function inverseNormal(p: number): number {
    // Bisection on the CDF: exact enough for a page and never out of range.
    let lo = -8;
    let hi = 8;

    for (let i = 0; i < 60; i++) {
        const mid = (lo + hi) / 2;

        if (normalCdf(mid) < p) {
            lo = mid;
        } else {
            hi = mid;
        }
    }

    return (lo + hi) / 2;
}

/** A chance as people say it: "about 1 in 3", "very likely", "unlikely". */
export function chanceWords(p: number): string {
    if (p >= 0.9) {
        return 'very likely';
    }

    if (p <= 0.07) {
        return 'unlikely';
    }

    const n = Math.round(1 / p);

    return p >= 0.6 ? `about ${Math.round(p * 100)}%` : `about 1 in ${n}`;
}

/**
 * The groups a person is compared in, from everyone in the same run: the whole run,
 * their department and their position (each left out when the person has none).
 */
export function peerGroups(
    rows: {
        score: number;
        employee: { department: string | null; position: string | null } | null;
    }[],
    person: { department: string | null; position: string | null },
): { key: string; label: string; scores: number[] }[] {
    const groups = [
        {
            key: 'all',
            label: 'Everyone in this run',
            scores: rows.map((r) => r.score),
        },
    ];

    if (person.department) {
        groups.push({
            key: 'department',
            label: person.department,
            scores: rows
                .filter((r) => r.employee?.department === person.department)
                .map((r) => r.score),
        });
    }

    if (person.position) {
        groups.push({
            key: 'position',
            label: person.position,
            scores: rows
                .filter((r) => r.employee?.position === person.position)
                .map((r) => r.score),
        });
    }

    return groups;
}

/** Whole days since an ISO time (0 for a missing one). */
export function daysSince(iso: string | null): number {
    return iso
        ? Math.floor((Date.now() - new Date(iso).getTime()) / 86_400_000)
        : 0;
}

/**
 * The person a link asks to open (`?person=<employee id>`), among a run's scores —
 * how one predictive screen opens a person on another (ADR 0099).
 */
export function personFromUrl<T extends { employee: { id: number } | null }>(
    scores: T[] | undefined,
): T | null {
    if (typeof window === 'undefined' || !scores) {
        return null;
    }

    const id = Number(
        new URLSearchParams(window.location.search).get('person'),
    );

    return id > 0 ? (scores.find((s) => s.employee?.id === id) ?? null) : null;
}

/** Drop `?person=` once the breakdown it opened is closed, so a reload does not reopen it. */
export function forgetPersonInUrl(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);

    if (url.searchParams.has('person')) {
        url.searchParams.delete('person');
        window.history.replaceState(window.history.state, '', url);
    }
}
