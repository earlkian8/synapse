export type EvaluationStatus = 'draft' | 'submitted' | 'acknowledged';

export type PeriodStatus = 'draft' | 'open' | 'closed';

/** The semantic tone a rating band carries — never a colour, always a meaning. */
export type BandTone = 'positive' | 'good' | 'neutral' | 'caution' | 'critical';

/** One cut of the tenant's rating model. */
export type RatingBand = {
    key: string;
    label: string;
    min_percent: number;
    description: string | null;
    tone: BandTone;
};

/** One weighted section of an appraisal framework. */
export type FrameworkSection = {
    key: string;
    name: string;
    description: string | null;
    weight: number;
};

/** How a framework leads with its result. */
export type ResultDisplay = 'band' | 'percent' | 'points';

/** How a line is measured: a numeric range, a percentage, or named levels. */
export type ScaleType = 'numeric' | 'percentage' | 'levels';

/** One named level of a descriptive scale, with its behavioural anchor. */
export type ScaleLevel = {
    value: number;
    label: string;
    description: string | null;
};

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

export type PerformanceScore = {
    id: number;
    label: string;
    description: string | null;
    weight: number;
    score: number | null;
    remarks: string | null;
    sort_order: number;
    // The section this line was measured in (snapshot).
    section_key: string;
    section_name: string | null;
    section_weight: number;
    // The line's own rating scale (snapshot at scoring time).
    scale_type: ScaleType;
    scale_name: string | null;
    scale_min: number;
    scale_max: number;
    scale_step: number;
    scale_levels: ScaleLevel[] | null;
    scale_descriptor: string;
    // Whether the source criterion still exists (null when not loaded).
    criterion_active: boolean | null;
};

/** One section's contribution to the result. */
export type SectionResult = {
    key: string;
    name: string | null;
    weight: number;
    percent: number | null;
    scored: number;
    total: number;
};

/** A derived appraisal result: attainment, its band, and the section breakdown. */
export type ScoreResult = {
    percent: number | null;
    normalized: number | null;
    band: RatingBand | null;
    sections: SectionResult[];
    scored: number;
    total: number;
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
    // The result, three ways.
    overall_percent: number | null;
    result_band: string | null;
    result_label: string | null;
    overall_score: number | null;
    // The framework snapshot this appraisal was conducted under.
    template_name: string | null;
    template_sections: FrameworkSection[];
    bands: RatingBand[];
    result_display: ResultDisplay;
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

/** One cycle in the employee's attainment history. */
export type HistoryPoint = {
    period: string | null;
    percent: number;
    score: number;
    label: string | null;
    status: EvaluationStatus;
    is_current: boolean;
};

/** Per-employee decision support attached to the scorecard. */
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

/** An appraisal framework, as the Performance module needs to read it. */
export type ReviewTemplateOption = {
    id: number;
    hashid: string;
    name: string;
    description: string | null;
    rating_scale_id: number | null;
    result_display: ResultDisplay;
    applies_to: 'all' | 'department' | 'position' | 'employment_type';
    applies_to_values: string[];
    is_default: boolean;
    is_active: boolean;
    is_archived: boolean;
    sections: FrameworkSection[];
    bands: RatingBand[];
    section_weight_total: number;
    items?: ReviewTemplateItem[];
    items_count: number;
    evaluations_count: number;
};

export type ReviewTemplateItem = {
    id: number;
    kpi_criterion_id: number | null;
    rating_scale_id: number | null;
    section_key: string;
    name: string;
    description: string | null;
    weight: number;
    sort_order: number;
};

export type PerformanceEmployee = {
    id: number;
    full_name: string;
    employee_no: string;
    department_id: number | null;
};

export type PerformanceDepartment = {
    id: number;
    name: string;
    headcount: number;
};

export type PerformanceStats = {
    total: number;
    draft: number;
    submitted: number;
    acknowledged: number;
    eligible: number;
    coverage: number | null;
    average_percent: number | null;
    average_score: number | null;
};

/** One band's share of a cycle's results. */
export type DistributionBand = {
    key: string;
    label: string;
    tone: BandTone;
    min_percent: number;
    count: number;
    share: number;
};

/** One department's calibration row. */
export type DepartmentCalibration = {
    department: string;
    total: number;
    completed: number;
    average_percent: number | null;
    top_band_share: number | null;
};

export type PerformancePermissions = { manage: boolean };

export type PerformanceIndexPageProps = {
    evaluations: PerformanceEvaluation[];
    periods: EvaluationPeriodOption[];
    templates: ReviewTemplateOption[];
    departments: PerformanceDepartment[];
    employees: PerformanceEmployee[];
    currentPeriodId: number | null;
    stats: PerformanceStats;
    distribution: DistributionBand[];
    byDepartment: DepartmentCalibration[];
    can: PerformancePermissions;
};

export type PerformanceShowPageProps = {
    evaluation: PerformanceEvaluation;
    result: ScoreResult;
    support: DecisionSupport;
    can: PerformancePermissions;
};
