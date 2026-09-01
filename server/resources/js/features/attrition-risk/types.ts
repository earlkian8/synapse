export type RiskTier = 'low' | 'medium' | 'high';

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
    confidence: number; // 0–1 — simulated confidence (see mock-engine.ts)
    /** Snapshot of the simulated feature values behind this score. */
    features: Record<string, number | string>;
    employee: RiskEmployee | null;
};

export type RiskRun = {
    id: number;
    hashid: string;
    status: 'completed' | 'failed';
    model_version: string | null;
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
