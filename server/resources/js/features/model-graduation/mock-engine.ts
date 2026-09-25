import type {
    FieldCoverage,
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
 * organisation of this size needs years to reach them, and for attrition, longer
 * still — departures are the slowest outcome of the three to accrue.
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
        unitOne: 'configured',
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
            unitOne: 'promotion',
            summary:
                'Past promotions are the examples anything built from your records would learn from.',
            basis: 'A model of this kind needs roughly 10 to 20 recorded outcomes for every piece of information it weighs. One built from your records would weigh the appraisal record and the dozen or so other records worth testing against it, so 120 is the lower bound. Below that, the pattern it finds moves with whichever handful of people happen to be on record.',
            source: 'Promotion records carrying an effective date and an approver.',
            outlook: promotionOutlook(c),
        },
        {
            key: 'review_cycles',
            label: 'Completed review cycles',
            group: 'volume',
            current: c.cycles,
            required: 3,
            unit: 'review cycles',
            unitOne: 'review cycle',
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
            unit: 'people held back for testing',
            unitOne: 'person held back for testing',
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
            unit: 'people in the smaller group',
            unitOne: 'person in the smaller group',
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
            unit: 'cycles on one appraisal form',
            unitOne: 'cycle on one appraisal form',
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
            unit: 'scores matched to an outcome',
            unitOne: 'score matched to an outcome',
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
            unit: 'cycle-to-cycle comparisons',
            unitOne: 'cycle-to-cycle comparison',
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
            unit: 'review cycles',
            unitOne: 'review cycle',
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
            unit: 'comparisons held back for testing',
            unitOne: 'comparison held back for testing',
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
            unit: 'people with three or more appraisals',
            unitOne: 'person with three or more appraisals',
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
            unit: 'cycles on one appraisal form',
            unitOne: 'cycle on one appraisal form',
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
            unit: 'forecasts matched to a rating',
            unitOne: 'forecast matched to a rating',
            summary:
                'Every forecast already stored is matched to the rating the person actually received.',
            basis: 'Without this link there is nothing to learn from later, however much time passes. It is the one requirement that has to hold from day one, because history cannot be reconstructed after the fact.',
            source: 'Stored forecasts joined to the employee’s subsequent appraisal results.',
        },
        isolationRequirement(),
    ]);
}

/**
 * Attrition Risk — scored by a model trained on an outside survey of workers, with
 * every score stored so it can later be matched to who actually left. Its gate is
 * still the furthest from opening of the three: departures accrue slowest.
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
            unitOne: 'departure',
            summary:
                'People who have actually left are the examples anything built from your records would learn from.',
            basis: 'A model of this kind needs roughly 10 to 20 recorded outcomes for every piece of information it uses. The risk score draws on 8, so around 80 departures is the lower bound — and a stable organisation produces them slowly, which is precisely why this gate is the hardest of the three to open.',
            source: 'Completed offboarding records with a final working day.',
            outlook: attritionOutlook(c),
        },
        {
            key: 'history_months',
            label: 'Months of headcount history',
            group: 'volume',
            current: 24,
            required: 24,
            unit: 'months of headcount history',
            unitOne: 'month of headcount history',
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
            unit: 'people held back for testing',
            unitOne: 'person held back for testing',
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
            unit: 'people in the smaller group',
            unitOne: 'person in the smaller group',
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
            unit: 'departures with a recorded reason',
            unitOne: 'departure with a recorded reason',
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
            current: EMPLOYEES,
            required: EMPLOYEES,
            unit: 'scores matched to an outcome',
            unitOne: 'score matched to an outcome',
            summary:
                'Every risk score already stored can be matched to whether the person later left.',
            basis: 'Without this link there is nothing to learn from later, however much time passes. It is the one requirement that has to hold from day one, because history cannot be reconstructed after the fact.',
            source: 'Stored risk scores joined to subsequent offboarding records.',
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

/*
 * Field coverage: every input a score draws on, how many employee records
 * actually carry it, and whether it reaches the score at all.
 *
 * The three states are the honest ones. `supplied` is fed in today. `available`
 * is the uncomfortable middle — the system records it, the score does not use it
 * yet. `missing` is a field no module produces, so no amount of data entry fixes
 * it. A count alone would hide that distinction, and it is the distinction that
 * tells someone what to do next.
 */

/** Employee-record fields every surface reads, always complete on an active record. */
function coreFields(): FieldCoverage[] {
    return [
        {
            key: 'date_hired',
            label: 'Hire date',
            source: 'Employee record',
            state: 'supplied',
            covered: EMPLOYEES,
            total: EMPLOYEES,
            note: 'Mandatory on every employee, so tenure is always available.',
        },
        {
            key: 'employment_type',
            label: 'Employment type',
            source: 'Employee record',
            state: 'supplied',
            covered: EMPLOYEES,
            total: EMPLOYEES,
            note: 'Regular, probationary, part-time or contractual.',
        },
        {
            key: 'department',
            label: 'Department',
            source: 'Employee record',
            state: 'supplied',
            covered: EMPLOYEES,
            total: EMPLOYEES,
            note: 'Every employee belongs to one.',
        },
        {
            key: 'salary',
            label: 'Monthly salary',
            source: 'Employee record',
            state: 'supplied',
            covered: EMPLOYEES,
            total: EMPLOYEES,
            note: 'Set at hiring and kept current through promotions.',
        },
    ];
}

/**
 * Appraisal-derived fields, whose coverage follows how many cycles have closed.
 * Each surface says which of them it reads and why the rest are left out.
 */
function appraisalFields(
    c: Counters,
    notes: {
        latest: string;
        prior: (prior: number) => string;
        priorUsed: boolean;
    },
): FieldCoverage[] {
    const latest = 35;
    const prior = c.cycles >= 2 ? EMPLOYEES_WITH_HISTORY : 0;
    const older = c.cycles >= 3 ? EMPLOYEES_WITH_HISTORY : 0;

    return [
        {
            key: 'rating_latest',
            label: 'Latest completed appraisal',
            source: 'Performance',
            state: 'supplied',
            covered: latest,
            total: EMPLOYEES,
            note: notes.latest.replace('{missing}', String(EMPLOYEES - latest)),
        },
        {
            key: 'rating_prior',
            label: 'Previous completed appraisal',
            source: 'Performance',
            state: notes.priorUsed ? 'supplied' : 'available',
            covered: prior,
            total: EMPLOYEES,
            note: notes.prior(prior),
        },
        {
            key: 'rating_older',
            label: 'Appraisal two cycles back',
            source: 'Performance',
            state: 'available',
            covered: older,
            total: EMPLOYEES,
            note: 'Measured across a large reference workforce: it adds nothing once the more recent appraisals are known, so it is not asked for.',
        },
    ];
}

/**
 * Mark fields a surface holds but deliberately does not read, each with the
 * reason — so "not used" is never mistaken for "not yet wired in".
 */
function notInputs(
    fields: FieldCoverage[],
    why: Record<string, string>,
): FieldCoverage[] {
    return fields.map((field) =>
        why[field.key]
            ? { ...field, state: 'available' as const, note: why[field.key] }
            : field,
    );
}

/**
 * Attendance and training figures the system records. Across the reference
 * workforce they have no (or a negligible) bearing on either appraisal surface's
 * scores, so neither reads them; promotion overrides overtime's note, since there
 * it does carry a little signal and is left out on purpose.
 */
function unfedOperationalFields(): FieldCoverage[] {
    return [
        {
            key: 'attendance_rate',
            label: 'Attendance rate, last 90 days',
            source: 'Attendance',
            state: 'available',
            covered: EMPLOYEES,
            total: EMPLOYEES,
            note: 'Recorded daily, but across the reference workforce it has no bearing on these scores, so it is not an input. Only this organisation’s own history could earn it a place.',
        },
        {
            key: 'late_days',
            label: 'Days late, last 90 days',
            source: 'Attendance',
            state: 'available',
            covered: EMPLOYEES,
            total: EMPLOYEES,
            note: 'Derived on every attendance record; a negligible bearing on these scores in the reference workforce, so not an input.',
        },
        {
            key: 'overtime',
            label: 'Approved overtime, last 90 days',
            source: 'Attendance',
            state: 'available',
            covered: EMPLOYEES,
            total: EMPLOYEES,
            note: 'Approved overtime minutes are stored per day; no bearing on the next appraisal in the reference workforce, so not an input.',
        },
        {
            key: 'training',
            label: 'Trainings completed, last 12 months',
            source: 'Training & Development',
            state: 'available',
            covered: 31,
            total: EMPLOYEES,
            note: `Enrolments and completions are tracked (${EMPLOYEES - 31} employees have none); training hours showed no bearing on these scores in the reference workforce, so they are not an input.`,
        },
    ];
}

function promotionFields(c: Counters): FieldCoverage[] {
    return [
        ...appraisalFields(c, {
            latest: 'The readiness score starts here. The {missing} employees without a completed appraisal are listed as not assessed rather than scored from tenure or department.',
            priorUsed: true,
            prior: (prior) =>
                (prior === 0
                    ? 'No second closed cycle exists yet, so nobody has one. '
                    : `Only ${prior} employees have been appraised in two closed cycles. `) +
                'It gives the change since the previous appraisal — the strongest signal of promotion — so until it exists a score says it rests on one appraisal.',
        }),
        ...notInputs(coreFields(), {
            date_hired:
                'Recorded on every employee. Tenure was measured and adds nothing to readiness once the appraisals are known.',
            employment_type:
                'Recorded, but had no effect on promotion in the reference workforce (which has no part-time category at all).',
            department:
                'Deliberately not an input. It is the reference workforce’s largest effect after improvement, but those departments are not this organisation’s, and a department’s past promotion rate says nothing about one person’s readiness.',
            salary: 'Deliberately not an input: the reference workforce’s salaries are in another currency and period.',
        }),
        {
            key: 'promotion_history',
            label: 'Promotion history',
            source: 'Employee 201 file',
            state: 'available',
            covered: 28,
            total: EMPLOYEES,
            note: 'Recorded, and left out on purpose: in the reference workforce the recently promoted were promoted again more often — backwards from any real time-in-grade practice.',
        },
        {
            key: 'certifications',
            label: 'Certifications',
            source: 'Employee 201 file',
            state: 'available',
            covered: 29,
            total: EMPLOYEES,
            note: 'Recorded; measured across the reference workforce and adds nothing to readiness once the appraisals are known.',
        },
        ...notInputs(unfedOperationalFields(), {
            overtime:
                'Recorded per day, and in the reference workforce it does lift promotion odds a little — left out on purpose: overtime depends on role and policy (exempt staff record none), and a readiness score that rises with hours worked penalises part-time staff and anyone with caring responsibilities.',
        }),
        {
            key: 'peer_feedback',
            label: 'Peer feedback',
            source: '—',
            state: 'missing',
            covered: 0,
            total: EMPLOYEES,
            note: 'No module collects peer or 360-degree feedback, so this cannot be filled in by data entry.',
        },
        {
            key: 'engagement',
            label: 'Engagement and job satisfaction',
            source: '—',
            state: 'missing',
            covered: 0,
            total: EMPLOYEES,
            note: 'The system runs no engagement survey, so there is nothing to read.',
        },
    ];
}

function performanceFields(c: Counters): FieldCoverage[] {
    const kpi = 35;

    return [
        ...appraisalFields(c, {
            latest: 'The one input the forecast needs: the latest appraisal completed before the period being forecast. The {missing} employees without one are listed as not forecast.',
            priorUsed: false,
            prior: (prior) =>
                (prior === 0
                    ? 'No second closed cycle exists yet. '
                    : `${prior} employees have one. `) +
                'Measured across a large reference workforce: once the latest appraisal is known it adds nothing to the forecast, so it is shown on the trajectory but not asked for.',
        }),
        ...notInputs(coreFields(), {
            date_hired:
                'Recorded on every employee. Tenure was measured and adds nothing to the forecast once the latest appraisal is known.',
            employment_type:
                'Recorded; no bearing on the next appraisal in the reference workforce.',
            department:
                'Recorded; no bearing on the next appraisal in the reference workforce, whose departments are not this organisation’s anyway.',
            salary: 'Not an input: the reference workforce’s salaries are in another currency and period.',
        }),
        {
            key: 'kpi_attainment',
            label: 'KPI attainment per cycle',
            source: 'Performance',
            state: 'available',
            covered: kpi,
            total: EMPLOYEES,
            note: 'Part of the appraisal the latest rating already summarises — sending it again would count it twice, and a forecast cannot know the next cycle’s KPIs before they happen.',
        },
        {
            key: 'certifications',
            label: 'Certifications',
            source: 'Employee 201 file',
            state: 'available',
            covered: 29,
            total: EMPLOYEES,
            note: 'Recorded; measured across the reference workforce and adds nothing to the forecast.',
        },
        ...unfedOperationalFields(),
        {
            key: 'deadline_adherence',
            label: 'Deadline adherence',
            source: '—',
            state: 'missing',
            covered: 0,
            total: EMPLOYEES,
            note: 'No project or task tracking exists in the system, so delivery reliability cannot be measured.',
        },
        {
            key: 'peer_feedback',
            label: 'Peer feedback',
            source: '—',
            state: 'missing',
            covered: 0,
            total: EMPLOYEES,
            note: 'No module collects peer or 360-degree feedback.',
        },
    ];
}

/** Attendance counts over the last 90 days, fed straight into the risk score. */
function attritionAttendanceFields(): FieldCoverage[] {
    const tracked = 38;
    const note = `Counted from the daily attendance records. ${EMPLOYEES - tracked} employees have no attendance tracked in the window, so theirs is estimated rather than read — which lowers their confidence.`;

    return [
        {
            key: 'absences',
            label: 'Absences, last 90 days',
            source: 'Attendance',
            state: 'supplied',
            covered: tracked,
            total: EMPLOYEES,
            note: `Approved leave, rest days and holidays are not absences. ${note}`,
        },
        {
            key: 'late_days',
            label: 'Late arrivals, last 90 days',
            source: 'Attendance',
            state: 'supplied',
            covered: tracked,
            total: EMPLOYEES,
            note,
        },
        {
            key: 'overtime',
            label: 'Overtime hours, last 90 days',
            source: 'Attendance',
            state: 'supplied',
            covered: tracked,
            total: EMPLOYEES,
            note: 'Hours worked beyond the shift, approved or not — the survey asked how much people worked, not how much was signed off.',
        },
    ];
}

function attritionFields(c: Counters): FieldCoverage[] {
    // Department is recorded but deliberately not an input: the survey's
    // free-text departments could not be matched to anybody's department list.
    const core = coreFields().map((field) =>
        field.key === 'department'
            ? {
                  ...field,
                  state: 'available' as const,
                  note: 'Recorded on every employee, but not an input — the survey this model learned from could not be matched to a department list.',
              }
            : field,
    );

    return [
        ...core,
        ...attritionAttendanceFields(),
        {
            key: 'since_promotion',
            label: 'Time since last promotion',
            source: 'Employee 201 file',
            state: 'supplied',
            covered: 28,
            total: EMPLOYEES,
            note: 'Recorded for employees with a promotion on file; for the rest, never having been promoted is itself the answer, and the wait is their whole tenure.',
        },
        {
            key: 'training',
            label: 'Trainings completed, last 12 months',
            source: 'Training & Development',
            state: 'available',
            covered: 31,
            total: EMPLOYEES,
            note: 'Tracked, but the survey the model learned from did not ask about training, so it cannot be an input until the model is retrained on this organisation’s own history.',
        },
        {
            key: 'departure_reason',
            label: 'Departure reason',
            source: 'Offboarding',
            state: 'available',
            covered: Math.max(0, c.primary - 2),
            total: c.primary,
            note: 'Counted against departures rather than headcount. Voluntary and involuntary exits mean opposite things, so every departure needs one.',
        },
        {
            key: 'exit_interview',
            label: 'Exit interview notes',
            source: '—',
            state: 'missing',
            covered: 0,
            total: c.primary,
            note: 'Offboarding records no structured exit interview, so the stated reason is all there is.',
        },
        {
            key: 'engagement',
            label: 'Engagement and job satisfaction',
            source: '—',
            state: 'missing',
            covered: 0,
            total: EMPLOYEES,
            note: 'The strongest published predictor of leaving, and the system runs no survey that would produce it.',
        },
        {
            key: 'pay_benchmark',
            label: 'Pay against market rate',
            source: '—',
            state: 'missing',
            covered: 0,
            total: EMPLOYEES,
            note: 'Salary is recorded, but nothing compares it to a market benchmark.',
        },
    ];
}

const FIELD_BUILDERS: Record<
    ModelKey,
    (counters: Counters) => FieldCoverage[]
> = {
    promotion: promotionFields,
    performance: performanceFields,
    attrition: attritionFields,
};

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
        fields: FIELD_BUILDERS[model](counters),
        employees: EMPLOYEES,
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
 * `useIsMobile`. The server snapshot always returns
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
