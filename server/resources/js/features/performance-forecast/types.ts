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
    confidence: number; // 0–1
    band: ForecastBand;
    history: ForecastHistoryPoint[];
    /** The grounded feature snapshot sent to the model (partial; rest imputed). */
    features: Record<string, number | string>;
    employee: ForecastEmployee | null;
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
    model_version: string | null;
    employees_scored: number;
    exceeds_count: number;
    on_track_count: number;
    below_count: number;
    average_rating: number | null;
    average_confidence: number | null;
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
    service: ServiceInfo;
    can: PerformanceForecastPermissions;
};
