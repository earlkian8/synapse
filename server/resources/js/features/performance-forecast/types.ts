import type { UnassessedEmployee } from '@/features/model-graduation/components/unassessed-list';
import type { Graduation } from '@/features/model-graduation/types';

export type ForecastBand = 'below' | 'on_track' | 'exceeds';

/** One actual rating in an employee's history (0–100), for the trajectory. */
export type ForecastHistoryPoint = {
    label: string | null;
    rating: number;
};

export type ForecastEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    position: string | null;
    department: string | null;
};

export type ForecastScore = {
    id: number;
    predicted_rating: number; // 0–100
    /** The range four in five next ratings land in (null on runs from before ranges). */
    predicted_low: number | null;
    predicted_high: number | null;
    /** 0–1: the chance the next rating lands in `band`. */
    confidence: number;
    band: ForecastBand;
    /** The completed appraisals the forecast could read, oldest first. */
    history: ForecastHistoryPoint[];
    /** The inputs sent to the model. */
    features: { rating_latest?: number };
    /** Notes about any input held at the model's trained range. */
    warnings: string[];
    employee: ForecastEmployee | null;
};

/**
 * How a run did once its period's appraisals were completed: every forecast
 * promised a range and a band, checked here against the actual result.
 */
export type ForecastTrackRecord = {
    checked: number;
    forecasts: number;
    /** Mean absolute miss, in rating points. */
    mean_error: number | null;
    /** Share of actual ratings inside their forecast's range (the promise: ~0.8). */
    within_range: number | null;
    /** Share whose band was right, and what the forecasts' confidence expected. */
    band_right: number | null;
    expected_band_right: number | null;
    /** Actual rating by employee id. */
    actuals: Record<number, number>;
};

export type ForecastTargetPeriod = {
    name: string;
    start_date: string | null;
    end_date: string | null;
};

export type ForecastRun = {
    id: number;
    hashid: string;
    status: 'completed' | 'failed';
    /** Whose model scored it: the organisation's own (model graduation) or the general one. */
    scored_by: 'own' | 'general';
    employees_scored: number;
    exceeds_count: number;
    on_track_count: number;
    below_count: number;
    average_rating: number | null;
    average_confidence: number | null;
    /** Active employees with no completed appraisal before the period, and why. */
    unassessed: UnassessedEmployee[];
    generated_by?: string | null;
    target_period?: ForecastTargetPeriod | null;
    created_at: string | null;
    forecasts: ForecastScore[];
};

/** A lightweight run for the history selector. */
export type RunSummary = {
    hashid: string;
    created_at: string | null;
    employees_scored: number;
    exceeds_count: number;
    average_rating: number | null;
};

/**
 * Only liveness: whether a new forecast can be run right now. The model's
 * identity and accuracy metrics are deliberately not sent to the browser — this
 * is an HR screen, not a model dashboard.
 */
export type ServiceInfo = {
    connected: boolean;
};

export type PerformanceForecastPermissions = { manage: boolean };

export type PerformanceForecastPageProps = {
    run: ForecastRun | null;
    runs: RunSummary[];
    track_record: ForecastTrackRecord | null;
    service: ServiceInfo;
    can: PerformanceForecastPermissions;
    /** Model graduation: moving from the general model to the organisation's own. */
    graduation: Graduation;
};
