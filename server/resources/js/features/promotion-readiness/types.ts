import type { UnassessedEmployee } from '@/features/model-graduation/components/unassessed-list';

export type ReadinessTier = 'low' | 'medium' | 'high';

/** How much appraisal history a score rests on. */
export type ReadinessBasis = 'latest_appraisal' | 'two_appraisals';

export type ReadinessFactor = {
    feature: 'rating_latest' | 'rating_change';
    label: string;
    /**
     * Readiness points this input moves the score, compared with a typical record;
     * sign matches `direction`.
     */
    impact: number;
    direction: 'up' | 'down';
};

/** A completed appraisal the score rests on (attainment, 0–100). */
export type ReadinessAppraisal = {
    label: string | null;
    rating: number;
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
    /** 0–100: where this record's promotion odds sit among the reference workforce. */
    score: number;
    /** 0–1: the share of reference employees with this record who were promoted within a year. */
    probability: number;
    tier: ReadinessTier;
    basis: ReadinessBasis | null;
    factors: ReadinessFactor[];
    /** The inputs sent to the model. */
    features: { rating_latest?: number; rating_change?: number };
    /** The completed appraisals behind them, oldest first. */
    history: ReadinessAppraisal[];
    /** Notes about any input held at the model's trained range. */
    warnings: string[];
    employee: ReadinessEmployee | null;
};

export type ReadinessRun = {
    id: number;
    hashid: string;
    status: 'completed' | 'failed';
    employees_scored: number;
    high_count: number;
    medium_count: number;
    low_count: number;
    average_score: number | null;
    /** Active employees the model declined — no completed appraisal — and why. */
    unassessed: UnassessedEmployee[];
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
