/**
 * The mood scale's colours (ADR 0102): "feeling blue" to "sunny", a diverging scale
 * on the blue-amber axis colour-blind readers keep, with a neutral midpoint. The same
 * steps as the web (app.css), each scheme's own, checked with the palette validator:
 * adjacent steps ΔE 17 (normal) and 16 (deutan) by day, 19 and 19 at night. Marks
 * only: text never wears them.
 */
export const MOOD_STEPS = {
  light: ['#2B3BA8', '#6A82EA', '#D4D2CD', '#EFA623', '#C2550A'],
  dark: ['#6F82F5', '#B4C0F8', '#6A6863', '#F9CC66', '#DD6316'],
} as const;

/** The ink a face's features are drawn in, on each scheme. */
export const MOOD_INK = { light: '#1B1F3B', dark: '#101223' } as const;

export const MOOD_WORDS = ['Rough', 'Low', 'Okay', 'Good', 'Great'] as const;

/** A mood between two steps, mixed channel by channel. */
export function moodColor(mood: number, scheme: 'light' | 'dark'): string {
  const steps = MOOD_STEPS[scheme];
  const value = Math.min(5, Math.max(1, mood));
  const low = Math.floor(value);
  const share = value - low;
  if (share < 0.01 || low === 5) return steps[low - 1];
  return mix(steps[low - 1], steps[low], share);
}

function mix(a: string, b: string, t: number): string {
  const channel = (hex: string, i: number) => parseInt(hex.slice(1 + i * 2, 3 + i * 2), 16);
  const out = [0, 1, 2].map((i) => Math.round(channel(a, i) + (channel(b, i) - channel(a, i)) * t));
  return `#${out.map((c) => c.toString(16).padStart(2, '0')).join('')}`;
}

/** "November" from "2026-11-01"; with `year`, "November 2026". */
export function monthName(period: string, year = false): string {
  const [y, m] = period.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'long', ...(year ? { year: 'numeric' } : {}) });
}

/** "N" for November: the year's row fits twelve faces across a phone. */
export function monthInitial(period: string): string {
  return monthName(period).slice(0, 1);
}

export function answerLabel(options: { value: number; label: string }[] | undefined, value: number): string {
  return options?.find((o) => o.value === value)?.label ?? String(value);
}
