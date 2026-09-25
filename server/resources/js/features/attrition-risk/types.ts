export type RiskTier = 'low' | 'medium' | 'high';

export type RiskFactor = {
    feature: string;
    label: string;
    /** Signed logit contribution; positive pushes risk up. */
    impact: number;
    direction: 'up' | 'down';
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
    /** Snapshot of the recorded values sent to the model. */
    features: Record<string, number | string>;
    employee: RiskEmployee | null;
};

export type RiskRun = {
    id: number;
    hashid: string;
    status: 'completed' | 'failed';
    employees_scored: number;
    high_count: number;
    medium_count: number;
    low_count: number;
    average_score: number | null;
    average_confidence: number | null;
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
};
