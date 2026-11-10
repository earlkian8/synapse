import type { Graduation } from '@/features/model-graduation/types';
export type RiskTier = 'low' | 'medium' | 'high';

/** What the data the model learned from shows for one answer (ADR 0098). */
export type RiskEvidence = {
    /** Share who left among people in the employee's band, and how many there were. */
    rate: number;
    people: number;
    /** The same for the typical band. */
    typical_rate: number | null;
    typical_people: number | null;
    overall_rate: number | null;
    /**
     * Whether the data on its own points the way the model's contribution does; null
     * when the two bands leave at about the same rate.
     */
    consistent: boolean | null;
};

export type RiskFactor = {
    feature: string;
    label: string;
    /**
     * Signed effect on the probability (0–1); positive pushes risk up. On runs with an
     * explanation it is an exact Shapley contribution: ×100, the risk points it adds
     * to a typical employee's score.
     */
    impact: number;
    direction: 'up' | 'down';
    /** The employee's recorded value and the typical one (runs from ADR 0098 on). */
    value?: number | string;
    typical?: number | string;
    /** The survey bands they fall in. */
    band?: string | null;
    typical_band?: string | null;
    evidence?: RiskEvidence | null;
    /** What kind of fact it is (runs from ADR 0101 on). */
    group?: FactorGroup | null;
    /**
     * For a behavioural indicator built from several inputs (withdrawal: absences and
     * lateness), whose `value` is its level: the recorded inputs, and the typical ones.
     */
    components?: Record<string, number | string> | null;
    typical_components?: Record<string, number | string> | null;
};

export type FactorGroup =
    'structural' | 'compensation' | 'career' | 'emotional';

/**
 * How the score reads a behavioural indicator (ADR 0101): as one of its inputs
 * (withdrawal), through the inputs it is built from (career stagnation), or not at all
 * (workload strain, effort–reward imbalance — tested, and they made it less accurate).
 */
export type IndicatorRead = 'direct' | 'inputs' | 'none';

/** One behavioural indicator of an emotional state, read from a person's record. */
export type RiskIndicatorReading = {
    key: string;
    /** Its level in words, and its place among the levels (0 = least). */
    level: string;
    rank: number;
    read: IndicatorRead;
    /** The risk points the score gives it; null when the score does not read it. */
    points: number | null;
    /** The recorded inputs it was read from. */
    inputs: Record<string, number | string>;
    /** The share who left at this level in the data the model learned from. */
    evidence: {
        rate: number;
        people: number;
        overall_rate: number | null;
    } | null;
};

/** What an indicator is, stated once per run. */
export type RiskIndicatorDefinition = {
    key: string;
    label: string;
    /** The emotional state the behaviour is read as. */
    indicates: string;
    theory: string;
    inputs: string[];
    levels: string[];
    read: IndicatorRead;
    /** Why the score does not read it, when it does not. */
    not_read: string | null;
    /**
     * Only the organisation's own records hold it (team mood, recognition, rest —
     * ADR 0102), so only its own model can learn it.
     */
    own_records?: boolean;
};

/**
 * The score the model gives the same record with one change HR can make (ADR 0099):
 * a promotion now, regular employment, a raise into the next pay band, or all of
 * them together.
 */
export type RiskScenario = {
    key: 'promotion' | 'regular' | 'pay_band' | 'together';
    changes: Record<string, number | string>;
    score: number;
};

/** Why a score is what it is: the typical employee's score its factors add up from. */
export type RiskExplanation = {
    baseline: number;
    /** Runs from ADR 0099 on. */
    scenarios?: RiskScenario[] | null;
    /** Runs from ADR 0101 on. */
    indicators?: RiskIndicatorReading[] | null;
};

/** One band of an input: how many people gave that answer, and how many left. */
export type EvidenceBand = {
    band: string;
    people: number;
    left: number;
    rate: number;
};

/** What a run's scores are read against (ADR 0098). */
export type RiskReference = {
    /** A typical employee's score (0–100). */
    baseline: number;
    typical: Record<string, { value: number | string; band: string | null }>;
    tiers: { medium_from: number; high_from: number };
    /** Leave rates by band in the data the model learned from. */
    evidence: {
        /** "survey" (the general model) or "own_records" (the organisation's). */
        source: 'survey' | 'own_records';
        people: number;
        left: number;
        overall_rate: number | null;
        features: Record<string, EvidenceBand[]>;
        /** By behavioural-indicator level (runs from ADR 0101 on). */
        indicators?: Record<string, EvidenceBand[]>;
    } | null;
    /** The behavioural indicators (runs from ADR 0101 on). */
    indicators?: RiskIndicatorDefinition[];
};

export type RiskEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    position: string | null;
    department: string | null;
};

export type RiskScore = {
    id: number;
    score: number; // 0–100 (higher = more likely to leave)
    probability: number; // 0–1
    tier: RiskTier;
    /** 0–1 — the share of the model's inputs grounded in this employee's record. */
    confidence: number;
    /** What moved this score, strongest first (empty when not attributable). */
    factors: RiskFactor[];
    /** Null on runs from before explanations. */
    explanation: RiskExplanation | null;
    /** Snapshot of the recorded values sent to the model. */
    features: Record<string, number | string>;
    employee: RiskEmployee | null;
};

export type RiskRun = {
    id: number;
    hashid: string;
    status: 'completed' | 'failed';
    /** Whose model scored it: the organisation's own (model graduation) or the general one. */
    scored_by: 'own' | 'general';
    employees_scored: number;
    high_count: number;
    medium_count: number;
    low_count: number;
    average_score: number | null;
    average_confidence: number | null;
    /** Null on runs from before explanations. */
    reference: RiskReference | null;
    generated_by?: string | null;
    created_at: string | null;
    scores: RiskScore[];
};

/** A lightweight run for the history selector. */
export type RunSummary = {
    hashid: string;
    created_at: string | null;
    employees_scored: number;
    high_count: number;
    average_score: number | null;
};

/**
 * Only liveness: whether a new assessment can be run right now. The model's
 * identity and accuracy metrics are deliberately not sent to the browser — this
 * is an HR screen, not a model dashboard.
 */
export type ServiceInfo = {
    connected: boolean;
};

export type AttritionRiskPermissions = { manage: boolean };

export type AttritionRiskPageProps = {
    run: RiskRun | null;
    runs: RunSummary[];
    service: ServiceInfo;
    can: AttritionRiskPermissions;
    /** Model graduation: moving from the general model to the organisation's own. */
    graduation: Graduation;
};
