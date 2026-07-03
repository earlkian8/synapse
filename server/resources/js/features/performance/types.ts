export type EvaluationStatus = 'draft' | 'submitted' | 'acknowledged';

export type PeriodStatus = 'draft' | 'open' | 'closed';

export type EvaluationEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    position: string | null;
    department: string | null;
};

export type EvaluationPeriodRef = {
    id: number;
    hashid: string;
    name: string;
    status: PeriodStatus;
    start_date: string | null;
    end_date: string | null;
};

/** How a score line is rated: an N-point scale, a percentage, or named levels. */
export type ScaleType = 'points' | 'percentage' | 'scale';

/** One named level of a descriptive rating scale. */
export type ScaleLevel = { label: string; value: number };

export type PerformanceScore = {
    id: number;
    label: string;
    weight: number;
    score: number | null;
    // The line's own rating scale (snapshot at scoring time).
    scale_type: ScaleType;
    scale_min: number;
    scale_max: number;
    scale_levels: ScaleLevel[] | null;
    remarks: string | null;
    // Whether the source criterion still exists (null when not loaded).
    criterion_active: boolean | null;
};

/** The LLM-generated performance read, available once HR generates it. */
export type PerformanceInsight = {
    available: true;
    headline: string;
    summary: string;
    strengths: string[];
    development_areas: string[];
    coaching_actions: string[];
    suggested_goals: string[];
    recommendation: string;
    generated_at: string;
};

/** Why insights couldn't be produced (key missing, quota, parse error). */
export type PerformanceInsightUnavailable = {
    available: false;
    reason: string;
    retryable: boolean;
};

export type PerformanceInsightResult =
    | PerformanceInsight
    | PerformanceInsightUnavailable;

export type PerformanceEvaluation = {
    id: number;
    hashid: string;
    status: EvaluationStatus;
    overall_score: number | null;
    submitted_at: string | null;
    acknowledged_at: string | null;
    remarks: string | null;
    scores_count: number;
    ai_insights: PerformanceInsight | null;
    employee?: EvaluationEmployee | null;
    period?: EvaluationPeriodRef | null;
    evaluator?: { id: number; name: string } | null;
    scores?: PerformanceScore[];
};

/** The ML forecast band for an employee's next period. */
export type ForecastBand = 'below' | 'on_track' | 'exceeds';

/** The latest ML performance forecast for this employee (0–100 predicted). */
export type PerformanceForecastSummary = {
    predicted_rating: number;
    band: ForecastBand;
    confidence: number;
    generated_at: string | null;
};

/** One point in the employee's overall-score history (1–5). */
export type HistoryPoint = {
    period: string | null;
    score: number;
    status: EvaluationStatus;
    is_current: boolean;
};

/** Per-employee decision support attached to the evaluation show page. */
export type DecisionSupport = {
    history: HistoryPoint[];
    forecast: PerformanceForecastSummary | null;
    ai_available: boolean;
};

export type EvaluationPeriodOption = {
    id: number;
    hashid: string;
    name: string;
    start_date: string | null;
    end_date: string | null;
    status: PeriodStatus;
    is_archived: boolean;
    evaluations_count: number;
};

export type PerformanceEmployee = {
    id: number;
    full_name: string;
    employee_no: string;
};

export type PerformanceStats = {
    total: number;
    draft: number;
    submitted: number;
    acknowledged: number;
    average_score: number | null;
};

export type PerformancePermissions = { manage: boolean };

export type PerformanceIndexPageProps = {
    evaluations: PerformanceEvaluation[];
    periods: EvaluationPeriodOption[];
    employees: PerformanceEmployee[];
    stats: PerformanceStats;
    can: PerformancePermissions;
};

export type PerformanceShowPageProps = {
    evaluation: PerformanceEvaluation;
    support: DecisionSupport;
    can: PerformancePermissions;
};
