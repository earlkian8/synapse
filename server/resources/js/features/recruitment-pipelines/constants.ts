import type { StageDraft, StageKind } from './types';

/**
 * The stage's meaning, in the plain-English terms a recruiter thinks in — no
 * open/won/lost jargon reaches the UI. Business logic still keys off `kind`.
 */
export const KIND_OPTIONS: { value: StageKind; label: string }[] = [
    { value: 'open', label: 'In progress' },
    { value: 'won', label: 'This is the Hired stage' },
    { value: 'lost', label: 'This is a Rejected / lost stage' },
];

export const KIND_LABELS: Record<StageKind, string> = {
    open: 'In progress',
    won: 'Hired stage',
    lost: 'Rejected / lost stage',
};

export const KIND_DOT: Record<StageKind, string> = {
    open: 'bg-indigo-400',
    won: 'bg-emerald-400',
    lost: 'bg-slate-400',
};

/** The classic 6-stage process — a fast starting point, not a hidden default. */
export const STANDARD_TEMPLATE: StageDraft[] = [
    { name: 'Applied', kind: 'open' },
    { name: 'Screening', kind: 'open' },
    { name: 'Interview', kind: 'open' },
    { name: 'Offer', kind: 'open' },
    { name: 'Hired', kind: 'won' },
    { name: 'Rejected', kind: 'lost' },
];
