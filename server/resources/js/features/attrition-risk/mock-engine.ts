import type {
    RiskEmployee,
    RiskRun,
    RiskScore,
    RiskTier,
    RunSummary,
} from './types';

/**
 * Attrition Risk is a frontend-only demo surface (see
 * docs/decisions/0030-attrition-risk-frontend-only.md): there is no server, database
 * or trained model behind it. This module fabricates a stable employee roster and
 * scores it with a small, self-consistent synthetic formula, entirely in the browser.
 * Runs persist to localStorage so "run assessment" / history / delete keep working
 * across reloads without a backend.
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

function pick<T>(rng: () => number, items: readonly T[]): T {
    return items[Math.floor(rng() * items.length)];
}

function clampRound(value: number, min: number, max: number): number {
    return Math.min(max, Math.max(min, Math.round(value)));
}

const FIRST_NAMES = [
    'Maria',
    'Jose',
    'Angela',
    'Miguel',
    'Andrea',
    'Carlo',
    'Bianca',
    'Rafael',
    'Camille',
    'Gabriel',
    'Nicole',
    'Marco',
    'Patricia',
    'Enzo',
    'Samantha',
    'Diego',
    'Julia',
    'Xavier',
    'Isabel',
    'Lucas',
    'Faith',
    'Adrian',
    'Kristine',
    'Nathan',
    'Danielle',
    'Joshua',
    'Erika',
    'Vincent',
    'Michelle',
    'Aaron',
] as const;

const LAST_NAMES = [
    'Santos',
    'Reyes',
    'Cruz',
    'Bautista',
    'Ocampo',
    'Garcia',
    'Torres',
    'Aquino',
    'Del Rosario',
    'Mendoza',
    'Villanueva',
    'Rivera',
    'Flores',
    'Castillo',
    'Ramos',
    'Navarro',
    'Domingo',
    'Salazar',
    'Manalo',
    'Pascual',
] as const;

const DEPARTMENTS: { name: string; positions: readonly string[] }[] = [
    {
        name: 'Engineering',
        positions: [
            'Software Engineer',
            'QA Analyst',
            'DevOps Engineer',
            'Engineering Lead',
        ],
    },
    {
        name: 'Sales',
        positions: ['Account Executive', 'Sales Associate', 'Sales Manager'],
    },
    {
        name: 'Marketing',
        positions: ['Marketing Specialist', 'Content Writer', 'Brand Manager'],
    },
    {
        name: 'Finance',
        positions: ['Accountant', 'Financial Analyst', 'Payroll Officer'],
    },
    {
        name: 'Human Resources',
        positions: ['HR Generalist', 'Recruiter', 'HR Business Partner'],
    },
    {
        name: 'Operations',
        positions: [
            'Operations Analyst',
            'Logistics Coordinator',
            'Operations Manager',
        ],
    },
    {
        name: 'Customer Support',
        positions: ['Support Specialist', 'Support Team Lead'],
    },
];

type Baseline = {
    yearsAtCompany: number;
    yearsSinceLastPromotion: number;
    yearsInCurrentRole: number;
    monthlyIncome: number;
    performanceTendency: number;
    overtimeTendency: number;
    trainingTendency: number;
};

const ROSTER_SEED = 90210;
const ROSTER_SIZE = 46;

function buildRoster(): {
    roster: RiskEmployee[];
    baselines: Map<number, Baseline>;
} {
    const rng = mulberry32(ROSTER_SEED);
    const used = new Set<string>();
    const roster: RiskEmployee[] = [];
    const baselines = new Map<number, Baseline>();

    for (let i = 1; i <= ROSTER_SIZE; i++) {
        let first = pick(rng, FIRST_NAMES);
        let last = pick(rng, LAST_NAMES);
        let full = `${first} ${last}`;
        let guard = 0;

        while (used.has(full) && guard++ < 25) {
            first = pick(rng, FIRST_NAMES);
            last = pick(rng, LAST_NAMES);
            full = `${first} ${last}`;
        }

        used.add(full);

        const dept = pick(rng, DEPARTMENTS);
        const position = pick(rng, dept.positions);
        const yearsAtCompany = Math.round((0.3 + rng() * 11.5) * 10) / 10;

        roster.push({
            id: i,
            full_name: full,
            initials: `${first[0]}${last[0]}`.toUpperCase(),
            employee_no: `EMP-${1000 + i}`,
            photo: null,
            position,
            department: dept.name,
        });

        baselines.set(i, {
            yearsAtCompany,
            yearsSinceLastPromotion:
                Math.round(Math.min(yearsAtCompany, rng() * 5) * 10) / 10,
            yearsInCurrentRole:
                Math.round(Math.min(yearsAtCompany, rng() * 4) * 10) / 10,
            monthlyIncome: Math.round((20000 + rng() * 70000) / 500) * 500,
            performanceTendency: 1.5 + rng() * 2.5,
            overtimeTendency: rng() * 0.75,
            trainingTendency: rng() * 3.5,
        });
    }

    return { roster, baselines };
}

const { roster: ROSTER, baselines: BASELINES } = buildRoster();

function tierFor(probability: number): RiskTier {
    if (probability < 0.33) {
        return 'low';
    }

    if (probability < 0.66) {
        return 'medium';
    }

    return 'high';
}

/** Score one employee for a single run, jittered around their stable baseline. */
function scoreEmployee(
    employee: RiskEmployee,
    baseline: Baseline,
    rng: () => number,
): RiskScore {
    const overtime: 'Yes' | 'No' =
        rng() < baseline.overtimeTendency ? 'Yes' : 'No';
    const performanceRating = clampRound(
        baseline.performanceTendency + (rng() * 1.4 - 0.7),
        1,
        4,
    );
    const trainingTimesLastYear = clampRound(
        baseline.trainingTendency + (rng() * 2 - 1),
        0,
        6,
    );
    const monthlyIncome =
        Math.round((baseline.monthlyIncome * (0.97 + rng() * 0.06)) / 100) *
        100;
    const { yearsAtCompany, yearsSinceLastPromotion, yearsInCurrentRole } =
        baseline;

    // A small, self-consistent synthetic formula — illustrative only, not a fitted
    // model. Overtime, stagnant promotion cadence, low pay and thin training push
    // risk up; tenure and recent training pull it down.
    let logit = -1.1;
    logit += overtime === 'Yes' ? 0.9 : -0.15;
    logit += Math.max(0, 3 - performanceRating) * 0.35;
    logit += Math.min(yearsSinceLastPromotion, 6) * 0.18;
    logit -= Math.min(yearsAtCompany, 10) * 0.05;
    logit += trainingTimesLastYear === 0 ? 0.4 : -trainingTimesLastYear * 0.08;
    logit -= ((monthlyIncome - 40000) / 40000) * 0.5;
    logit += rng() * 0.6 - 0.3;

    const probability = 1 / (1 + Math.exp(-logit));
    const confidence = Math.min(
        0.97,
        Math.max(
            0.15,
            0.35 +
                (Math.min(yearsAtCompany, 8) / 8) * 0.45 +
                (rng() * 0.2 - 0.1),
        ),
    );

    return {
        id: employee.id,
        score: Math.round(probability * 1000) / 10,
        probability,
        tier: tierFor(probability),
        confidence: Math.round(confidence * 100) / 100,
        features: {
            OverTime: overtime,
            MonthlyIncome: monthlyIncome,
            YearsAtCompany: yearsAtCompany,
            YearsSinceLastPromotion: yearsSinceLastPromotion,
            YearsInCurrentRole: yearsInCurrentRole,
            PerformanceRating: performanceRating,
            TrainingTimesLastYear: trainingTimesLastYear,
            Department: employee.department ?? '—',
        },
        employee,
    };
}

let runCounter = 0;

/** Generate a fresh run over the demo roster (not persisted — see {@link runAssessment}). */
function generateRun(): RiskRun {
    const timestamp = Date.now();
    const rng = mulberry32(hashSeed(`run-${timestamp}-${++runCounter}`));

    const scores = ROSTER.map((employee) => {
        const baseline = BASELINES.get(employee.id);

        if (!baseline) {
            throw new Error(`No baseline for demo employee ${employee.id}`);
        }

        return scoreEmployee(employee, baseline, rng);
    }).sort((a, b) => b.score - a.score);

    const high_count = scores.filter((s) => s.tier === 'high').length;
    const medium_count = scores.filter((s) => s.tier === 'medium').length;
    const low_count = scores.filter((s) => s.tier === 'low').length;
    const average = (values: number[]) =>
        values.reduce((sum, v) => sum + v, 0) / values.length;

    return {
        id: timestamp,
        hashid: `demo-${timestamp.toString(36)}-${Math.floor(rng() * 1e6).toString(36)}`,
        status: 'completed',
        employees_scored: scores.length,
        high_count,
        medium_count,
        low_count,
        average_score:
            Math.round(average(scores.map((s) => s.score)) * 10) / 10,
        average_confidence:
            Math.round(average(scores.map((s) => s.confidence)) * 100) / 100,
        generated_by: null,
        created_at: new Date(timestamp).toISOString(),
        scores,
    };
}

const STORAGE_KEY = 'synapse:attrition-risk:runs';
const MAX_STORED_RUNS = 10;
const EMPTY_RUNS: RiskRun[] = [];

function readStore(): RiskRun[] {
    if (typeof window === 'undefined') {
        return [];
    }

    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);
        const parsed: unknown = raw ? JSON.parse(raw) : [];

        return Array.isArray(parsed)
            ? (parsed as RiskRun[]).sort((a, b) => b.id - a.id)
            : [];
    } catch {
        return [];
    }
}

function writeStore(runs: RiskRun[]): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify(runs.slice(0, MAX_STORED_RUNS)),
        );
    } catch {
        // Storage full or unavailable (e.g. private browsing) — the run still
        // renders for this page load, it just won't survive a reload.
    }
}

/*
 * A tiny external store over the localStorage-backed run list, so the page can
 * read it via `useSyncExternalStore`. That (not a `useEffect` + `setState`) is
 * how this codebase reads browser-only state safely — see `useAppearance` /
 * `useIsMobile` — because it lets React reconcile the SSR pass (which always
 * gets `getServerRunsSnapshot`'s empty array) against the real client value
 * without a hydration mismatch. `cachedRuns` is only ever replaced (never
 * mutated) with a fresh array, so it's safe to hand straight back as the
 * snapshot's reference identity.
 */
let cachedRuns: RiskRun[] = EMPTY_RUNS;
let seeded = false;
const listeners = new Set<() => void>();

function notify(): void {
    listeners.forEach((listener) => listener());
}

/** First client subscription: load from storage, seeding one run if empty. */
function ensureSeeded(): void {
    if (seeded || typeof window === 'undefined') {
        return;
    }

    seeded = true;
    cachedRuns = readStore();

    if (cachedRuns.length === 0) {
        const run = generateRun();
        cachedRuns = [run];
        writeStore(cachedRuns);
    }
}

export function subscribeRuns(callback: () => void): () => void {
    ensureSeeded();
    listeners.add(callback);

    return () => listeners.delete(callback);
}

export function getRunsSnapshot(): RiskRun[] {
    return typeof window === 'undefined' ? EMPTY_RUNS : cachedRuns;
}

export function getServerRunsSnapshot(): RiskRun[] {
    return EMPTY_RUNS;
}

export function toSummary(run: RiskRun): RunSummary {
    return {
        hashid: run.hashid,
        created_at: run.created_at,
        employees_scored: run.employees_scored,
        high_count: run.high_count,
        average_score: run.average_score,
    };
}

/** Generate a new run and persist it, evicting the oldest beyond {@link MAX_STORED_RUNS}. */
export function runAssessment(): RiskRun {
    ensureSeeded();

    const run = generateRun();
    cachedRuns = [run, ...cachedRuns];
    writeStore(cachedRuns);
    notify();

    return run;
}

/** Remove a historical run by hashid. */
export function deleteRun(hashid: string): void {
    ensureSeeded();

    cachedRuns = cachedRuns.filter((run) => run.hashid !== hashid);
    writeStore(cachedRuns);
    notify();
}
