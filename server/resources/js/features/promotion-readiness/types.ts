export type ReadinessTier = 'low' | 'medium' | 'high';

export type ReadinessFactor = {
    feature: string;
    label: string;
    /** Signed logit contribution; sign matches `direction`. */
    impact: number;
    direction: 'up' | 'down';
};

export type ReadinessEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    position: string | null;
    department: string | null;
};

export type ReadinessScore = {
    id: number;
    score: number; // 0–100
    probability: number; // 0–1
    tier: ReadinessTier;
    factors: ReadinessFactor[];
    employee: ReadinessEmployee | null;
};

export type ReadinessRun = {
    id: number;
    hashid: string;
    status: 'completed' | 'failed';
    model_version: string | null;
    employees_scored: number;
    high_count: number;
    medium_count: number;
    low_count: number;
    average_score: number | null;
    generated_by?: string | null;
    created_at: string | null;
    scores: ReadinessScore[];
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

export type PromotionReadinessPermissions = { manage: boolean };

export type PromotionReadinessPageProps = {
    run: ReadinessRun | null;
    runs: RunSummary[];
    service: ServiceInfo;
    can: PromotionReadinessPermissions;
};
