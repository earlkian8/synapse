import type { AnswerKey, Answers, Question } from './types';

/**
 * The mood scale's colours: CSS variables set per scheme in app.css (ADR 0102),
 * "feeling blue" to "sunny" with a neutral midpoint. Marks only, never text.
 */
export const MOOD_VARS = [
    'var(--mood-1)',
    'var(--mood-2)',
    'var(--mood-3)',
    'var(--mood-4)',
    'var(--mood-5)',
] as const;

export const MOOD_WORDS = ['Rough', 'Low', 'Okay', 'Good', 'Great'] as const;

/** The colour of a mood, 1–5; between two steps, mixed in OKLCH. */
export function moodColor(mood: number): string {
    const value = Math.min(5, Math.max(1, mood));
    const low = Math.floor(value);
    const share = value - low;

    if (share < 0.01 || low === 5) {
        return MOOD_VARS[low - 1];
    }

    return `color-mix(in oklch, ${MOOD_VARS[low - 1]} ${Math.round((1 - share) * 100)}%, ${MOOD_VARS[low]})`;
}

/** A mean mood in words: the nearest step. */
export function moodWord(mood: number): string {
    return MOOD_WORDS[Math.min(4, Math.max(0, Math.round(mood) - 1))];
}

/** "3.6". */
export function formatMean(value: number | undefined): string {
    return value === undefined ? '—' : value.toFixed(1);
}

/** A 0–1 share as a whole percentage. */
export function formatShare(value: number | undefined): string {
    return value === undefined ? '—' : `${Math.round(value * 100)}%`;
}

/** "November" from "2026-11-01"; with `year`, "November 2026". */
export function monthName(period: string, year = false): string {
    const [y, m] = period.split('-').map(Number);

    return new Date(y, m - 1, 1).toLocaleDateString(undefined, {
        month: 'long',
        ...(year ? { year: 'numeric' } : {}),
    });
}

/** "Nov". */
export function monthShort(period: string): string {
    const [y, m] = period.split('-').map(Number);

    return new Date(y, m - 1, 1).toLocaleDateString(undefined, {
        month: 'short',
    });
}

/** The label of an answer, as the question words it. */
export function answerLabel(
    questions: Question[],
    key: AnswerKey,
    value: number,
): string {
    return (
        questions
            .find((q) => q.key === key)
            ?.options.find((o) => o.value === value)?.label ?? String(value)
    );
}

/** UWES-3 engagement from a set of answers, 0–6. */
export function engagementOf(answers: Partial<Answers>): number | null {
    const { energy, enthusiasm, immersion } = answers;

    if (
        energy === undefined ||
        enthusiasm === undefined ||
        immersion === undefined
    ) {
        return null;
    }

    return Math.round(((energy + enthusiasm + immersion) / 3) * 10) / 10;
}
