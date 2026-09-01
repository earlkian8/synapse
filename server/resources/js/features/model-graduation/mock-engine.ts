import type {
    ModelCheck,
    ModelKey,
    Requirement,
    RequirementStatus,
    Stage,
} from './types';

/**
 * Model graduation is a frontend-only surface: there is no retraining job, no
 * per-organisation model artifact and no server behind it. This module fabricates
 * a readiness check per predictive surface entirely in the browser so the
 * lifecycle — and, more importantly, the REFUSAL to train on insufficient data —
 * can be shown end to end.
 *
 * The thresholds are the honest ones. They are what a real implementation would
 * have to enforce, and they are why none of the three surfaces graduates: an
 * organisation of this size needs years to reach them, and for attrition, decades.
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
const EMPLOYEES = 42;

/** Employees holding a scored appraisal in more than one cycle. */
const EMPLOYEES_WITH_HISTORY = 21;

const YEAR = new Date().getFullYear();

/** The shared "system readiness" requirement — true for every surface. */
function isolationRequirement(): Omit<Requirement, 'status'> {
    return {
        key: 'model_isolation',
        label: 'Per-organisation model storage',
        group: 'system',
        current: 1,
        required: 1,
        unit: 'configured',
        summary:
            'Anything built from your records stays yours, and never scores another organisation’s people.',
        basis: 'The application is multi-tenant, so a model trained on one organisation’s history is that organisation’s data. Sharing it across tenants would leak the workforce it learned from.',
        source: 'Per-organisation artifact storage in the inference service.',
    };
}

/** Counters that advance between checks, per surface. */
type Counters = { primary: number; cycles: number };

const BASELINE: Record<ModelKey, Counters> = {
    promotion: { primary: 14, cycles: 2 },
    performance: { primary: 21, cycles: 2 },
    attrition: { primary: 6, cycles: 2 },
};

/** Ceiling for each surface's primary counter, so re-checking cannot run away. */
const PRIMARY_TARGET: Record<ModelKey, number> = {
    promotion: 120,
    performance: 200,
    attrition: 80,
};

function statusFor(current: number, required: number): RequirementStatus {
    if (current >= required) {
        return 'met';
    }

    return current > 0 ? 'progressing' : 'waiting';
}

function withStatus(items: Omit<Requirement, 'status'>[]): Requirement[] {
    return items.map((item) => ({
        ...item,
        status: statusFor(item.current, item.required),
    }));
}

/**
 * Promotion Readiness — a classifier learning from who was actually promoted.
 */
function promotionRequirements(c: Counters): Requirement[] {
    const holdout = Math.floor(c.primary * 0.2);

    return withStatus([
        {
            key: 'promotion_outcomes',
            label: 'Promotions on record',
            group: 'volume',
            current: c.primary,
            required: PRIMARY_TARGET.promotion,
            unit: 'promotions',
            summary:
                'Past promotions are the examples anything built from your records would learn from.',
            basis: 'A model of this kind needs roughly 10 to 20 recorded outcomes for every piece of information it uses. The readiness score draws on 12, so 120 is the lower bound. Below that, the pattern it finds moves with whichever handful of people happen to be on record.',
            source: 'Promotion records carrying an effective date and an approver.',
            outlook: promotionOutlook(c),
        },
        {
            key: 'review_cycles',
            label: 'Completed review cycles',
            group: 'volume',
            current: c.cycles,
            required: 3,
            unit: 'cycles',
            summary:
                'Readiness leans on appraisal history, so there has to be some history to lean on.',
            basis: 'Two cycles give a current rating and one prior; a third is what separates a trend from a single change. Fewer, and the score is effectively reading one appraisal.',
            source: 'Evaluation periods with a status of closed.',
            outlook: cycleOutlook(c.cycles, 3),
        },
        {
            key: 'holdout_rows',
            label: 'People held back for testing',
            group: 'volume',
            current: holdout,
            required: 25,
            unit: 'people',
            summary:
                'A group set aside and never learned from, used to check the result actually works.',
            basis: 'A fifth of the records are held back. Under about 25 people a test result swings on one or two individuals, so it cannot tell a good result from a lucky one.',
            source: '20% of the promotion records, reserved and never trained on.',
            derived: true,
        },
        {
            key: 'outcome_balance',
            label: 'People in the smaller group',
            group: 'quality',
            current: Math.min(c.primary, EMPLOYEES - c.primary),
            required: 30,
            unit: 'people',
            summary:
                'Both answers — promoted and not promoted — have to appear often enough to tell apart.',
            basis: 'When one outcome is rare, the safest guess is always the common one, and the model stops distinguishing anybody. Thirty is the point at which the rarer group carries enough signal to resist that.',
            source: 'The smaller of the promoted and not-promoted groups.',
            derived: true,
        },
        {
            key: 'framework_stability',
            label: 'Cycles on one appraisal form',
            group: 'quality',
            current: Math.min(c.cycles, 2),
            required: 3,
            unit: 'cycles',
            summary:
                'Ratings only compare across cycles if the form did not change underneath them.',
            basis: 'Appraisal frameworks are configurable per organisation, which is deliberate — but it means a 4.0 measured on one form is not the same fact as a 4.0 on another. Learning across an edit teaches the form change, not the people.',
            source: 'Cycles completed since the last framework or rating-scale edit.',
            derived: true,
        },
        {
            key: 'outcome_linkage',
            label: 'Scores checked against what happened',
            group: 'quality',
            current: EMPLOYEES,
            required: EMPLOYEES,
            unit: 'scores',
            summary:
                'Every score already stored is matched to whether that person was later promoted.',
            basis: 'Without this link there is nothing to learn from later, however much time passes. It is the one requirement that has to hold from day one, because history cannot be reconstructed after the fact.',
            source: 'Stored assessment scores joined to the employee’s subsequent promotion records.',
        },
        isolationRequirement(),
    ]);
}

/**
 * Performance Forecast — a regressor learning this cycle's rating from the last.
 * Its examples are cycle-to-cycle comparisons, not people.
 */
function performanceRequirements(c: Counters): Requirement[] {
    const holdout = Math.floor(c.primary * 0.2);
    // Three appraisals are needed before a person contributes a trend.
    const trendDepth = Math.max(0, (c.cycles - 2) * EMPLOYEES_WITH_HISTORY);

    return withStatus([
        {
            key: 'cycle_pairs',
            label: 'Cycle-to-cycle comparisons',
            group: 'volume',
            current: c.primary,
            required: PRIMARY_TARGET.performance,
            unit: 'comparisons',
            summary:
                'One comparison is a person’s rating in one cycle set beside their rating in the next.',
            basis: 'Each pair of consecutive cycles yields one example per appraised employee. Predicting a number rather than a yes/no needs more examples than a classifier, and about 200 is where the error stops being dominated by how the split happened to fall.',
            source: 'Employees with a scored appraisal in two consecutive closed cycles.',
            outlook: performanceOutlook(c),
        },
        {
            key: 'review_cycles',
            label: 'Completed review cycles',
            group: 'volume',
            current: c.cycles,
            required: 4,
            unit: 'cycles',
            summary:
                'Forecasting the next cycle from this one needs several finished cycles to compare.',
            basis: 'Three consecutive cycles give two comparisons in sequence, which is the minimum for a direction of travel; the fourth is held back so the result can be tested on a cycle it never saw.',
            source: 'Evaluation periods with a status of closed.',
            outlook: cycleOutlook(c.cycles, 4),
        },
        {
            key: 'holdout_rows',
            label: 'Comparisons held back for testing',
            group: 'volume',
            current: holdout,
            required: 40,
            unit: 'comparisons',
            summary:
                'A slice set aside and never learned from, used to check the forecast actually works.',
            basis: 'A fifth of the comparisons are held back. Below about 40, the average error moves more with which comparisons landed in the test than with how good the forecast is.',
            source: '20% of the cycle-to-cycle comparisons, reserved and never trained on.',
            derived: true,
        },
        {
            key: 'trend_depth',
            label: 'People with three or more appraisals',
            group: 'quality',
            current: trendDepth,
            required: 30,
            unit: 'people',
            summary:
                'A trajectory needs three points. With two, every forecast is really last year restated.',
            basis: 'The forecast leans hardest on the previous rating. Without a third appraisal there is no way to tell someone climbing from someone who has plateaued at the same level, so the forecast cannot improve on simply repeating the last score.',
            source: 'Employees holding three or more scored appraisals.',
            derived: true,
        },
        {
            key: 'framework_stability',
            label: 'Cycles on one appraisal form',
            group: 'quality',
            current: Math.min(c.cycles, 2),
            required: 3,
            unit: 'cycles',
            summary:
                'Ratings only compare across cycles if the form did not change underneath them.',
            basis: 'Appraisal frameworks are configurable per organisation, which is deliberate — but it means a 4.0 measured on one form is not the same fact as a 4.0 on another. A forecast learned across an edit is tracking the form, not the person.',
            source: 'Cycles completed since the last framework or rating-scale edit.',
            derived: true,
        },
        {
            key: 'outcome_linkage',
            label: 'Forecasts checked against actual ratings',
            group: 'quality',
            current: EMPLOYEES,
            required: EMPLOYEES,
            unit: 'forecasts',
            summary:
                'Every forecast already stored is matched to the rating the person actually received.',
            basis: 'Without this link there is nothing to learn from later, however much time passes. It is the one requirement that has to hold from day one, because history cannot be reconstructed after the fact.',
            source: 'Stored forecasts joined to the employee’s subsequent appraisal results.',
        },
        isolationRequirement(),
    ]);
}

/**
 * Attrition Risk — no model exists at all, and nothing is being recorded that one
 * could later learn from. Its gate is the furthest from opening of the three.
 */
function attritionRequirements(c: Counters): Requirement[] {
    const holdout = Math.floor(c.primary * 0.2);

    return withStatus([
        {
            key: 'departures',
            label: 'Departures on record',
            group: 'volume',
            current: c.primary,
            required: PRIMARY_TARGET.attrition,
            unit: 'departures',
            summary:
                'People who have actually left are the examples anything built from your records would learn from.',
            basis: 'A model of this kind needs roughly 10 to 20 recorded outcomes for every piece of information it uses. Around 80 departures is the lower bound for the signals this surface shows — and a stable organisation produces them slowly, which is precisely why this gate is the hardest of the three to open.',
            source: 'Completed offboarding records with a final working day.',
            outlook: attritionOutlook(c),
        },
        {
            key: 'history_months',
            label: 'Months of headcount history',
            group: 'volume',
            current: 24,
            required: 24,
            unit: 'months',
            summary:
                'Enough elapsed time for leaving and staying to both be observable.',
            basis: 'Flight risk is a question about the future, so the records have to span long enough that people who stayed are genuinely distinguishable from people who had not left yet. Two years is the shortest window that holds.',
            source: 'Time elapsed since the earliest employment record.',
        },
        {
            key: 'holdout_rows',
            label: 'People held back for testing',
            group: 'volume',
            current: holdout,
            required: 20,
            unit: 'people',
            summary:
                'A group set aside and never learned from, used to check the result actually works.',
            basis: 'A fifth of the records are held back. Under about 20 people a test result swings on one or two individuals, so it cannot tell a good result from a lucky one.',
            source: '20% of the departure records, reserved and never trained on.',
            derived: true,
        },
        {
            key: 'outcome_balance',
            label: 'People in the smaller group',
            group: 'quality',
            current: c.primary,
            required: 30,
            unit: 'people',
            summary:
                'Both answers — left and stayed — have to appear often enough to tell apart.',
            basis: 'Departures are the rare outcome by a wide margin. When one answer is rare, the safest guess is always the common one, and the model stops distinguishing anybody.',
            source: 'The smaller of the departed and still-employed groups.',
            derived: true,
        },
        {
            key: 'exit_reasons',
            label: 'Departures with a recorded reason',
            group: 'quality',
            current: Math.max(0, c.primary - 2),
            required: c.primary,
            unit: 'departures',
            summary:
                'A resignation and a redundancy are different events and cannot be learned from together.',
            basis: 'Voluntary and involuntary exits have opposite meanings for flight risk. Mixing them teaches the model to predict headcount change rather than the decision to leave, so every departure needs a recorded reason before any of them is usable.',
            source: 'Offboarding records carrying a departure reason.',
            derived: true,
        },
        {
            key: 'outcome_linkage',
            label: 'Scores checked against what happened',
            group: 'quality',
            current: 0,
            required: EMPLOYEES,
            unit: 'scores',
            summary:
                'Nothing shown here is stored, so none of it can be matched to who actually left.',
            basis: 'This surface generates its scores in the browser and keeps no server-side record, so there is nothing to compare against later. Until scores are persisted and matched to outcomes, no amount of elapsed time moves this surface closer to a model of its own.',
            source: 'Stored risk scores joined to subsequent offboarding records.',
            outlook:
                'Waiting does not move this one. Scores would have to start being stored and matched to who actually left before any clock starts running — which is why this surface is the furthest of the three from a model of its own.',
        },
        isolationRequirement(),
    ]);
}

const BUILDERS: Record<ModelKey, (counters: Counters) => Requirement[]> = {
    promotion: promotionRequirements,
    performance: performanceRequirements,
    attrition: attritionRequirements,
};

function stageFor(requirements: Requirement[]): Stage {
    if (requirements.every((r) => r.status === 'met')) {
        return 'graduated';
    }

    const linkage = requirements.find((r) => r.key === 'outcome_linkage');

    return linkage?.status === 'met' ? 'collecting' : 'provisional';
}

/**
 * The requirement furthest from being satisfied — the one that actually holds
 * graduation back, as opposed to the others that merely also aren't met.
 *
 * Derived requirements are excluded: held-out rows and outcome balance rise on
 * their own as records accumulate, so naming one of them as the blocker would
 * point at something nobody can act on.
 */
function bindingRequirement(requirements: Requirement[]): Requirement | null {
    const unmet = requirements.filter((r) => r.status !== 'met' && !r.derived);

    if (unmet.length === 0) {
        return null;
    }

    return unmet.reduce((worst, candidate) =>
        candidate.current / candidate.required < worst.current / worst.required
            ? candidate
            : worst,
    );
}

/*
 * Outlooks: what it would take to close a shortfall, in plain language. They are
 * attached to the requirements that can be acted on directly, and the panel shows
 * the one belonging to whichever of those is furthest away — so the number on
 * screen and the sentence explaining it always describe the same thing.
 *
 * Straight-line and deliberately rounded: an order-of-magnitude statement, not a
 * forecast.
 */

function years(remaining: number, perYear: number): number {
    return Math.ceil(remaining / perYear);
}

function promotionOutlook(c: Counters): string {
    const remaining = PRIMARY_TARGET.promotion - c.primary;

    if (remaining <= 0) {
        return 'Enough promotions are on record for this to be built now.';
    }

    const n = years(remaining, 7);

    return `At about 7 promotions a year on current records, the ${remaining} still needed is roughly ${n} years away — around ${YEAR + n}.`;
}

function performanceOutlook(c: Counters): string {
    const remaining = PRIMARY_TARGET.performance - c.primary;

    if (remaining <= 0) {
        return 'Enough comparisons are on record for this to be built now.';
    }

    const cycles = Math.ceil(remaining / EMPLOYEES_WITH_HISTORY);
    const n = Math.ceil(cycles / 2);

    return `Each closed cycle adds about ${EMPLOYEES_WITH_HISTORY} comparisons, so the ${remaining} still needed is roughly ${cycles} more cycles — about ${n} years, around ${YEAR + n}.`;
}

function attritionOutlook(c: Counters): string {
    const remaining = PRIMARY_TARGET.attrition - c.primary;

    if (remaining <= 0) {
        return 'Enough departures are on record for this to be built now.';
    }

    const n = years(remaining, 4);

    return `At about 4 departures a year on current records, the ${remaining} still needed is roughly ${n} years away — around ${YEAR + n}. A stable organisation produces them slowly, which is exactly why this gate is the hardest to open.`;
}

function cycleOutlook(current: number, required: number): string {
    const remaining = Math.max(0, required - current);

    if (remaining === 0) {
        return 'Enough cycles have closed for this to be satisfied.';
    }

    const months = remaining * 6;

    return `Cycles close about twice a year, so ${remaining} more is roughly ${months} months away.`;
}

let checkCounter = 0;

/** Build a check for one surface (not persisted — see {@link runCheck}). */
function generateCheck(model: ModelKey, counters: Counters): ModelCheck {
    const timestamp = Date.now();
    const rng = mulberry32(hashSeed(`${model}-${timestamp}-${++checkCounter}`));
    const requirements = BUILDERS[model](counters);
    const binding = bindingRequirement(requirements);

    return {
        model,
        hashid: `${model}-${timestamp.toString(36)}-${Math.floor(rng() * 1e6).toString(36)}`,
        checked_at: new Date(timestamp).toISOString(),
        stage: stageFor(requirements),
        requirements,
        met_count: requirements.filter((r) => r.status === 'met').length,
        total_count: requirements.length,
        binding_key: binding?.key ?? '',
    };
}

/**
 * Advance the counters a little between checks, so re-checking behaves like an
 * organisation accruing records rather than reshuffling random numbers. Growth is
 * small on purpose — the gate is the point.
 */
function advance(model: ModelKey, previous: ModelCheck | null): Counters {
    if (!previous) {
        return { ...BASELINE[model] };
    }

    const rng = mulberry32(hashSeed(previous.hashid));
    const find = (key: string) =>
        previous.requirements.find((r) => r.key === key)?.current;

    const primaryKey = {
        promotion: 'promotion_outcomes',
        performance: 'cycle_pairs',
        attrition: 'departures',
    }[model];

    const primary =
        (find(primaryKey) ?? BASELINE[model].primary) + Math.floor(rng() * 3);

    // A cycle closes far less often than an individual record lands.
    const cycles =
        (find('review_cycles') ?? BASELINE[model].cycles) +
        (rng() < 0.12 ? 1 : 0);

    return {
        primary: Math.min(primary, PRIMARY_TARGET[model]),
        cycles: Math.min(cycles, 6),
    };
}

const STORAGE_PREFIX = 'synapse:model-graduation:';
const MODELS: ModelKey[] = ['promotion', 'performance', 'attrition'];

function readStored(model: ModelKey): ModelCheck | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        const raw = window.localStorage.getItem(`${STORAGE_PREFIX}${model}`);

        return raw ? (JSON.parse(raw) as ModelCheck) : null;
    } catch {
        return null;
    }
}

function writeStored(model: ModelKey, check: ModelCheck): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(
            `${STORAGE_PREFIX}${model}`,
            JSON.stringify(check),
        );
    } catch {
        // Storage full or unavailable (e.g. private browsing) — the check still
        // renders for this page load, it just won't survive a reload.
    }
}

/*
 * A tiny external store per surface, read through `useSyncExternalStore` rather
 * than a `useEffect` + `setState` — the same pattern as `useAppearance` /
 * `useIsMobile` and the Attrition Risk demo. The server snapshot always returns
 * null, so the SSR pass and the first client render agree and no hydration
 * mismatch is possible. Each cached check is only ever replaced, never mutated,
 * so it is safe to hand straight back as the snapshot's reference identity.
 */
const cache = new Map<ModelKey, ModelCheck | null>(
    MODELS.map((model) => [model, null]),
);
const seeded = new Set<ModelKey>();
const listeners = new Map<ModelKey, Set<() => void>>(
    MODELS.map((model) => [model, new Set<() => void>()]),
);

function notify(model: ModelKey): void {
    listeners.get(model)?.forEach((listener) => listener());
}

/** First client subscription for a surface: load from storage, seeding if empty. */
function ensureSeeded(model: ModelKey): void {
    if (seeded.has(model) || typeof window === 'undefined') {
        return;
    }

    seeded.add(model);

    const stored = readStored(model);
    const check = stored ?? generateCheck(model, { ...BASELINE[model] });

    cache.set(model, check);

    if (!stored) {
        writeStored(model, check);
    }
}

/*
 * `useSyncExternalStore` resubscribes whenever the subscribe function's identity
 * changes, so both accessors are bound once per surface and handed back from a
 * map rather than rebuilt on each call.
 */
const subscribers = new Map<ModelKey, (callback: () => void) => () => void>(
    MODELS.map((model) => [
        model,
        (callback: () => void) => {
            ensureSeeded(model);
            listeners.get(model)?.add(callback);

            return () => {
                listeners.get(model)?.delete(callback);
            };
        },
    ]),
);

const snapshots = new Map<ModelKey, () => ModelCheck | null>(
    MODELS.map((model) => [
        model,
        () =>
            typeof window === 'undefined' ? null : (cache.get(model) ?? null),
    ]),
);

/** The stable subscribe function for one surface. */
export function subscribe(
    model: ModelKey,
): (callback: () => void) => () => void {
    return subscribers.get(model)!;
}

/** The stable client snapshot reader for one surface. */
export function getSnapshot(model: ModelKey): () => ModelCheck | null {
    return snapshots.get(model)!;
}

export function getServerSnapshot(): ModelCheck | null {
    return null;
}

/** Re-evaluate one surface's requirements and persist the result. */
export function runCheck(model: ModelKey): ModelCheck {
    ensureSeeded(model);

    const check = generateCheck(
        model,
        advance(model, cache.get(model) ?? null),
    );
    cache.set(model, check);
    writeStored(model, check);
    notify(model);

    return check;
}
