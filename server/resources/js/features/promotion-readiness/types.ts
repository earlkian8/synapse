import type { UnassessedEmployee } from '@/features/model-graduation/components/unassessed-list';
import type { Graduation } from '@/features/model-graduation/types';

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
    /** The employee's value and the typical record's (runs from ADR 0098 on). */
    value?: number;
    typical?: number;
};

/** Readiness after the next appraisal, by the rating it gives. */
export type ReadinessOutlook = {
    points: { rating: number; score: number }[];
    /** The lowest next rating that reaches each tier (null: none on the scale does). */
    medium_from: number | null;
    high_from: number | null;
    /** Readiness if the next appraisal repeats the latest one. */
    same_again: number | null;
};

/** Why a score is what it is (ADR 0098). */
export type ReadinessExplanation = {
    /** A typical record's score with the same history; the factors add up from it. */
    baseline: number;
    outlook?: ReadinessOutlook | null;
};

/** What a run's scores are read against (ADR 0098). */
export type ReadinessReference = {
    base_rate: number;
    typical: { rating_latest: number; rating_change: number };
    /** Where each tier begins on the score scale. */
    tier_scores: { medium: number; high: number };
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
    /** Null on runs from before explanations. */
    explanation: ReadinessExplanation | null;
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
    /** Whose model scored it: the organisation's own (model graduation) or the general one. */
    scored_by: 'own' | 'general';
    /**
     * The promotion rate of the history its model learned from, when that was the
     * organisation's own; null for the general model (`REFERENCE_PROMOTION_RATE`).
     */
    base_rate: number | null;
    employees_scored: number;
    high_count: number;
    medium_count: number;
    low_count: number;
    average_score: number | null;
    /** Active employees the model declined — no completed appraisal — and why. */
    unassessed: UnassessedEmployee[];
    /** Null on runs from before explanations. */
    reference: ReadinessReference | null;
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
    /** Model graduation: moving from the general model to the organisation's own. */
    graduation: Graduation;
};
