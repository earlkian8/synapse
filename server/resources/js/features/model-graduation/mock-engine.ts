import type {
    CheckSummary,
    GraduationCheck,
    Requirement,
    RequirementStatus,
    Stage,
} from './types';

/**
 * Model Graduation is a frontend-only demo surface: there is no server, no
 * retraining job and no per-organisation model artifact behind it. This module
 * fabricates a readiness check entirely in the browser so the lifecycle — and,
 * more importantly, the REFUSAL to retrain on insufficient data — can be shown
 * end to end. Checks persist to localStorage so history and delete keep working.
 *
 * The thresholds below are the honest ones. They are what a real implementation
 * would have to enforce, and they are why the demo never graduates: an
 * organisation of this size needs well over a decade to reach them.
 */

/** Deterministic PRNG (mulberry32) — same seed always produces the same sequence. */
function mulberry32(seed: number): () => number {
    let a = seed >>> 0;

    return () => {
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;

        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function hashSeed(text: string): number {
    let h = 2166136261;

    for (let i = 0; i < text.length; i++) {
        h ^= text.charCodeAt(i);
        h = Math.imul(h, 16777619);
    }

    return h >>> 0;
}

/** Active employees in the demo organisation. */
const EMPLOYEES_TRACKED = 42;

/** Employees holding a scored appraisal in more than one cycle. */
const EMPLOYEES_WITH_HISTORY = 21;

/** Years of history the demo organisation has on record. */
const RECORD_YEARS = 2;

const DATASET_ORIGIN = 'General public dataset (non-Philippine)';

/** Thresholds a local model must clear before it may be trained. */
const THRESHOLDS = {
    promotionOutcomes: 120,
    reviewCycles: 4,
    holdoutRows: 25,
    outcomeBalance: 30,
    frameworkStability: 3,
} as const;

/** Where the demo organisation starts. */
const BASELINE = {
    promotionOutcomes: 14,
    reviewCycles: 2,
    frameworkStability: 2,
};

type Counters = typeof BASELINE;

function statusFor(current: number, required: number): RequirementStatus {
    if (current >= required) {
        return 'met';
    }

    return current > 0 ? 'progressing' : 'waiting';
}

function requirement(partial: Omit<Requirement, 'status'>): Requirement {
    return { ...partial, status: statusFor(partial.current, partial.required) };
}

/**
 * The seven requirements, derived from the organisation's current counters so
 * every number on the page stays consistent with every other.
 */
function buildRequirements(counters: Counters): Requirement[] {
    // Cycle-to-cycle training pairs, a fifth of which are held back for testing.
    const pairs =
        Math.max(0, counters.reviewCycles - 1) * EMPLOYEES_WITH_HISTORY;
    const holdout = Math.floor(pairs * 0.2);

    return [
        requirement({
            key: 'promotion_outcomes',
            label: 'Promotion outcomes on record',
            group: 'volume',
            current: counters.promotionOutcomes,
            required: THRESHOLDS.promotionOutcomes,
            unit: 'promotions',
            summary:
                'Past promotions are the examples the model would learn from.',
            basis: 'A logistic model needs roughly 10 to 20 recorded outcomes for every input it uses. The promotion model sends 12 inputs, so 120 is the lower bound. Below that the coefficients move with whichever handful of people happen to be in the data.',
            source: 'Promotion records carrying an effective date and an approver.',
        }),
        requirement({
            key: 'review_cycles',
            label: 'Completed review cycles',
            group: 'volume',
            current: counters.reviewCycles,
            required: THRESHOLDS.reviewCycles,
            unit: 'cycles',
            summary:
                'Forecasting next cycle from this one needs several finished cycles to compare.',
            basis: 'Each pair of consecutive cycles yields one training example per employee. Three pairs is the minimum for a trend, and a fourth cycle is held back so the result can be tested on data the model never saw.',
            source: 'Evaluation periods with a status of closed.',
        }),
        requirement({
            key: 'holdout_rows',
            label: 'Rows reserved for testing',
            group: 'volume',
            current: holdout,
            required: THRESHOLDS.holdoutRows,
            unit: 'rows',
            summary:
                'A slice of data the model never trains on, used to check whether it actually works.',
            basis: 'A fifth of the data is held back. Under about 25 rows a test result swings wildly on one or two people, so it cannot tell a good model from a lucky one.',
            source: '20% of the eligible employee-cycle pairs.',
        }),
        requirement({
            key: 'outcome_balance',
            label: 'Employees in the smaller outcome group',
            group: 'quality',
            current: Math.min(
                counters.promotionOutcomes,
                EMPLOYEES_TRACKED - counters.promotionOutcomes,
            ),
            required: THRESHOLDS.outcomeBalance,
            unit: 'employees',
            summary:
                'Both answers — promoted and not promoted — have to appear often enough to learn from.',
            basis: 'When one outcome is rare the model scores best by always predicting the common one, and stops distinguishing anybody. Thirty is the point at which the rare group carries enough signal to resist that.',
            source: 'The smaller of the promoted and not-promoted groups.',
        }),
        requirement({
            key: 'framework_stability',
            label: 'Consecutive cycles on one appraisal form',
            group: 'quality',
            current: counters.frameworkStability,
            required: THRESHOLDS.frameworkStability,
            unit: 'cycles',
            summary:
                'Scores only compare across cycles if the form did not change underneath them.',
            basis: 'Appraisal frameworks are configurable per organisation, which is deliberate — but it means a 4.0 measured on one form is not the same fact as a 4.0 on another. Training across an edit teaches the model the form change, not the people.',
            source: 'Cycles completed since the last framework or rating-scale edit.',
        }),
        requirement({
            key: 'outcome_linkage',
            label: 'Predictions matched to what happened',
            group: 'quality',
            current: EMPLOYEES_TRACKED,
            required: EMPLOYEES_TRACKED,
            unit: 'predictions',
            summary:
                'Every stored prediction is checked against the employee’s later record.',
            basis: 'Without this link there is nothing to learn from later, no matter how much time passes. It is the one requirement that must be satisfied from day one, because history cannot be reconstructed after the fact.',
            source: 'Stored run scores joined to the employee’s subsequent promotion and appraisal records.',
        }),
        requirement({
            key: 'model_isolation',
            label: 'Per-organisation model storage',
            group: 'system',
            current: 1,
            required: 1,
            unit: 'configured',
            summary:
                'A trained model is stored against one organisation and never scores another’s people.',
            basis: 'The application is multi-tenant, so a model trained on one organisation’s history is that organisation’s data. Sharing it across tenants would leak the workforce it learned from.',
            source: 'Per-organisation artifact storage in the inference service.',
        }),
    ];
}

function stageFor(requirements: Requirement[]): Stage {
    if (requirements.every((r) => r.status === 'met')) {
        return 'graduated';
    }

    const linkage = requirements.find((r) => r.key === 'outcome_linkage');

    return linkage?.status === 'met' ? 'collecting' : 'provisional';
}

/**
 * The requirement furthest from being satisfied — the one that actually holds
 * graduation back, as opposed to the six that merely also aren't met.
 */
function bindingRequirement(requirements: Requirement[]): Requirement | null {
    const unmet = requirements.filter((r) => r.status !== 'met');

    if (unmet.length === 0) {
        return null;
    }

    return unmet.reduce((worst, candidate) =>
        candidate.current / candidate.required < worst.current / worst.required
            ? candidate
            : worst,
    );
}

let checkCounter = 0;

/** Build a check from a set of counters (not persisted — see {@link runCheck}). */
function generateCheck(counters: Counters): GraduationCheck {
    const timestamp = Date.now();
    const rng = mulberry32(hashSeed(`check-${timestamp}-${++checkCounter}`));
    const requirements = buildRequirements(counters);
    const binding = bindingRequirement(requirements);

    const observedRate =
        Math.round((counters.promotionOutcomes / RECORD_YEARS) * 10) / 10;

    const remaining = Math.max(
        0,
        THRESHOLDS.promotionOutcomes - counters.promotionOutcomes,
    );

    const projectedYear =
        remaining === 0
            ? new Date().getFullYear()
            : observedRate > 0
              ? new Date().getFullYear() + Math.ceil(remaining / observedRate)
              : null;

    return {
        id: timestamp,
        hashid: `grad-${timestamp.toString(36)}-${Math.floor(rng() * 1e6).toString(36)}`,
        checked_at: new Date(timestamp).toISOString(),
        stage: stageFor(requirements),
        dataset_origin: DATASET_ORIGIN,
        employees_tracked: EMPLOYEES_TRACKED,
        requirements,
        met_count: requirements.filter((r) => r.status === 'met').length,
        total_count: requirements.length,
        binding_key: binding?.key ?? '',
        observed_rate: observedRate,
        projected_year: projectedYear,
    };
}

/**
 * Advance the counters a little between checks, so re-checking behaves like a
 * real organisation accruing records rather than reshuffling random numbers.
 * Growth is small on purpose — the gate is the point.
 */
function advance(previous: GraduationCheck | undefined): Counters {
    if (!previous) {
        return { ...BASELINE };
    }

    const rng = mulberry32(hashSeed(previous.hashid));
    const find = (key: string) =>
        previous.requirements.find((r) => r.key === key);

    const promotionOutcomes =
        (find('promotion_outcomes')?.current ?? BASELINE.promotionOutcomes) +
        Math.floor(rng() * 3);

    // A cycle closes far less often than a promotion lands.
    const reviewCycles =
        (find('review_cycles')?.current ?? BASELINE.reviewCycles) +
        (rng() < 0.15 ? 1 : 0);

    return {
        promotionOutcomes: Math.min(
            promotionOutcomes,
            THRESHOLDS.promotionOutcomes,
        ),
        reviewCycles: Math.min(reviewCycles, THRESHOLDS.reviewCycles),
        frameworkStability:
            find('framework_stability')?.current ?? BASELINE.frameworkStability,
    };
}

const STORAGE_KEY = 'synapse:model-graduation:checks';
const MAX_STORED_CHECKS = 10;
const EMPTY_CHECKS: GraduationCheck[] = [];

function readStore(): GraduationCheck[] {
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const parsed: unknown = raw ? JSON.parse(raw) : [];

        return Array.isArray(parsed)
            ? (parsed as GraduationCheck[]).sort((a, b) => b.id - a.id)
            : [];
    } catch {
        return [];
    }
}

function writeStore(checks: GraduationCheck[]): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify(checks.slice(0, MAX_STORED_CHECKS)),
        );
    } catch {
        // Storage full or unavailable (e.g. private browsing) — the check still
        // renders for this page load, it just won't survive a reload.
    }
}

/*
 * A tiny external store over the localStorage-backed check list, read through
 * `useSyncExternalStore` rather than a `useEffect` + `setState` — the same
 * pattern as `useAppearance` / `useIsMobile` and the Attrition Risk demo. Its
 * server snapshot always returns the empty array, so the SSR pass and the first
 * client render agree and no hydration mismatch is possible. `cachedChecks` is
 * only ever replaced with a fresh array, never mutated, so it is safe to hand
 * straight back as the snapshot's reference identity.
 */
let cachedChecks: GraduationCheck[] = EMPTY_CHECKS;
let seeded = false;
const listeners = new Set<() => void>();

function notify(): void {
    listeners.forEach((listener) => listener());
}

/** First client subscription: load from storage, seeding one check if empty. */
function ensureSeeded(): void {
    if (seeded || typeof window === 'undefined') {
        return;
    }

    seeded = true;
    cachedChecks = readStore();

    if (cachedChecks.length === 0) {
        cachedChecks = [generateCheck({ ...BASELINE })];
        writeStore(cachedChecks);
    }
}

export function subscribeChecks(callback: () => void): () => void {
    ensureSeeded();
    listeners.add(callback);

    return () => listeners.delete(callback);
}

export function getChecksSnapshot(): GraduationCheck[] {
    return typeof window === 'undefined' ? EMPTY_CHECKS : cachedChecks;
}

export function getServerChecksSnapshot(): GraduationCheck[] {
    return EMPTY_CHECKS;
}

export function toSummary(check: GraduationCheck): CheckSummary {
    return {
        hashid: check.hashid,
        checked_at: check.checked_at,
        met_count: check.met_count,
        total_count: check.total_count,
        stage: check.stage,
    };
}

/** Run a fresh readiness check and persist it, evicting the oldest beyond the cap. */
export function runCheck(): GraduationCheck {
    ensureSeeded();

    const check = generateCheck(advance(cachedChecks[0]));
    cachedChecks = [check, ...cachedChecks];
    writeStore(cachedChecks);
    notify();

    return check;
}

/** Remove a historical check by hashid. */
export function deleteCheck(hashid: string): void {
    ensureSeeded();

    cachedChecks = cachedChecks.filter((check) => check.hashid !== hashid);
    writeStore(cachedChecks);
    notify();
}
