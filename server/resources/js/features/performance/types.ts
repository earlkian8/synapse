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
    PerformanceInsight | PerformanceInsightUnavailable;

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
    // When calibration moved the rating: what the scorecard itself gave.
    scored_band: string | null;
    scored_label: string | null;
    calibrated_at: string | null;
    submitted_at: string | null;
    /** When the employee could first read it; null while calibration holds it. */
    shared_at: string | null;
    acknowledged_at: string | null;
    employee_comment: string | null;
    acknowledged_by?: { name: string; is_employee: boolean } | null;
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
    /** The range four in five next ratings land in (null on older runs). */
    predicted_low: number | null;
    predicted_high: number | null;
    band: ForecastBand;
    /** 0–1: the chance the next rating lands in `band`. */
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

/** The counts the section switcher shows. */
export type PerformanceNavCounts = { reviews: number };

// ── Reviews (ADR 0072) ──────────────────────────────────────────────────────

/** Who a reviewer is to the person appraised — derived, never typed. */
export type ReviewRelationship = 'self' | 'manager' | 'peer' | 'direct_report';

export type ReviewStatus = 'pending' | 'submitted' | 'declined' | 'cancelled';

/** One request as HR sees it on the scorecard. */
export type ReviewRequest = {
    id: number;
    hashid: string;
    relationship: ReviewRelationship;
    status: ReviewStatus;
    due_on: string | null;
    overdue: boolean;
    submitted_at: string | null;
    declined_at: string | null;
    decline_reason: string | null;
    reminded_at: string | null;
    can_remind: boolean;
    reviewer: {
        id: number;
        name: string;
        initials: string;
        photo: string | null;
    } | null;
};

/** One relationship's column in the comparison. */
export type FeedbackColumn = {
    key: ReviewRelationship;
    label: string;
    answered: number;
    asked: number;
    /** Pools (peers, direct reports) open at two answers. */
    shown: boolean;
};

export type FeedbackValue = {
    score: number;
    formatted: string;
    fraction: number | null;
    count: number;
};

export type FeedbackLine = {
    id: number;
    values: Partial<Record<ReviewRelationship, FeedbackValue | null>>;
    remarks: {
        relationship: ReviewRelationship;
        by: string | null;
        text: string;
    }[];
};

export type FeedbackComment = {
    relationship: ReviewRelationship;
    /** Named for self and manager; never for a pool. */
    by: string | null;
    strengths: string | null;
    improvements: string | null;
};

export type FeedbackSummary = {
    requests: ReviewRequest[];
    counts: { asked: number; submitted: number; pending: number };
    columns: FeedbackColumn[];
    lines: FeedbackLine[];
    comments: FeedbackComment[];
};

/** A colleague HR may ask to review an appraisal. */
export type ReviewCandidate = {
    id: number;
    full_name: string;
    initials: string;
    department: string | null;
    relationship: ReviewRelationship;
    has_account: boolean;
    is_evaluator: boolean;
    asked: 'pending' | 'submitted' | null;
};

/** A review as its reviewer reads it. */
export type MyReview = {
    id: number;
    hashid: string;
    relationship: ReviewRelationship;
    status: ReviewStatus;
    due_on: string | null;
    overdue: boolean;
    strengths: string | null;
    improvements: string | null;
    decline_reason: string | null;
    submitted_at: string | null;
    declined_at: string | null;
    created_at: string | null;
    requested_by?: string | null;
    open: boolean | null;
    subject: {
        id: number;
        full_name: string;
        initials: string;
        photo: string | null;
        position: string | null;
        department: string | null;
    } | null;
    period: {
        id: number;
        name: string;
        start_date: string | null;
        end_date: string | null;
    } | null;
    template_name: string | null;
};

export type ReviewAnswer = { score: number | null; remarks: string | null };

export type ReviewsPageProps = {
    reviews: MyReview[];
    has_employee: boolean;
    nav: PerformanceNavCounts;
};

export type ReviewFormPageProps = {
    review: MyReview;
    criteria: PerformanceScore[];
    sections: FrameworkSection[];
    answers: Record<number, ReviewAnswer>;
    nav: PerformanceNavCounts;
};

// ── My appraisals (ADR 0072) ────────────────────────────────────────────────

export type MyAppraisal = {
    hashid: string;
    status: EvaluationStatus;
    shared: boolean;
    calibrating: boolean;
    template_name: string | null;
    period: {
        name: string;
        start_date: string | null;
        end_date: string | null;
    } | null;
    evaluator: string | null;
    result_label: string | null;
    result_tone: BandTone | null;
    overall_percent: number | null;
    shared_at: string | null;
    acknowledged_at: string | null;
    self_review: {
        hashid: string;
        status: ReviewStatus;
        due_on: string | null;
        open: boolean;
    } | null;
};

export type MyAppraisalsPageProps = {
    appraisals: MyAppraisal[];
    has_employee: boolean;
    nav: PerformanceNavCounts;
};

export type SelfReviewRead = {
    strengths: string | null;
    improvements: string | null;
    submitted_at: string | null;
    lines: Record<
        number,
        {
            score: number | null;
            formatted: string | null;
            remarks: string | null;
        }
    >;
};

export type MyAppraisalPageProps = {
    evaluation: PerformanceEvaluation;
    result: ScoreResult;
    selfReview: SelfReviewRead | null;
    nav: PerformanceNavCounts;
};

// ── Goals (ADR 0073) ────────────────────────────────────────────────────────

export type GoalMeasure = 'percent' | 'number';

export type GoalStatus = 'active' | 'achieved' | 'missed' | 'dropped';

export type GoalHealth = 'on_track' | 'at_risk' | 'off_track';

export type GoalCheckIn = {
    id: number;
    value: number;
    value_label: string;
    progress: number;
    health: GoalHealth;
    note: string | null;
    author: string | null;
    by_owner: boolean;
    created_at: string | null;
};

export type PerformanceGoal = {
    id: number;
    hashid: string;
    title: string;
    description: string | null;
    measure: GoalMeasure;
    start_value: number;
    target_value: number;
    current_value: number;
    unit: string | null;
    start_label: string;
    target_label: string;
    current_label: string;
    progress: number;
    weight: number;
    due_on: string | null;
    status: GoalStatus;
    health: GoalHealth | null;
    is_stale: boolean;
    last_check_in_at: string | null;
    closed_at: string | null;
    created_at: string | null;
    from_library: boolean;
    created_by_owner: boolean;
    check_ins_count: number;
    employee?: {
        id: number;
        full_name: string;
        initials: string;
        photo: string | null;
        department: string | null;
        department_id: number | null;
    } | null;
    period?: { id: number; name: string; status: PeriodStatus } | null;
    check_ins?: GoalCheckIn[];
};

/** A goal-library entry. */
export type GoalTemplateOption = {
    id: number;
    hashid?: string;
    name: string;
    description: string | null;
    measure: GoalMeasure;
    start_value: number;
    target_value: number;
    unit: string | null;
    target_label?: string;
    is_active?: boolean;
    is_archived?: boolean;
    goals_count?: number;
};

/** A goal-library entry as Company Setup manages it. */
export type GoalLibraryEntry = GoalTemplateOption & {
    hashid: string;
    target_label: string;
    is_active: boolean;
    is_archived: boolean;
    goals_count: number;
};

export type GoalStats = {
    total: number;
    people: number;
    on_track: number;
    at_risk: number;
    off_track: number;
    stale: number;
    achieved: number;
    average_progress: number | null;
};

export type GoalEmployeeOption = {
    id: number;
    full_name: string;
    employee_no: string;
    department_id: number | null;
    department: string | null;
};

export type GoalsPageProps = {
    goals: PerformanceGoal[];
    stats: GoalStats;
    periods: EvaluationPeriodOption[];
    currentPeriodId: number | null;
    templates: GoalTemplateOption[];
    employees: GoalEmployeeOption[];
    departments: { id: number; name: string }[];
    focus: string | null;
    can: PerformancePermissions;
    nav: PerformanceNavCounts;
};

export type MyGoalsPageProps = {
    goals: PerformanceGoal[];
    attainment: number | null;
    periods: { id: number; name: string; status: PeriodStatus }[];
    currentPeriodId: number | null;
    templates: GoalTemplateOption[];
    focus: string | null;
    has_employee: boolean;
    nav: PerformanceNavCounts;
};

// ── Calibration (ADR 0073) ──────────────────────────────────────────────────

export type SessionStatus = 'open' | 'completed' | 'cancelled';

export type CalibrationSession = {
    id: number;
    hashid: string;
    name: string;
    status: SessionStatus;
    scheduled_for: string | null;
    notes: string | null;
    department_ids: number[] | null;
    scope_label: string;
    completed_at: string | null;
    created_at: string | null;
    adjustments_count: number;
    facilitator?: string | null;
    participants?: { id: number; name: string }[];
    period?: { id: number; name: string; status: PeriodStatus } | null;
};

export type CalibrationRow = {
    id: number;
    hashid: string;
    status: EvaluationStatus;
    held: boolean;
    overall_percent: number | null;
    template_name: string | null;
    bands: RatingBand[];
    scored: RatingBand | null;
    current: RatingBand | null;
    calibrated: boolean;
    last_adjustment: {
        reason: string;
        by: string | null;
        session: string | null;
        created_at: string | null;
    } | null;
    evaluator: string | null;
    employee: {
        id: number;
        user_id: number | null;
        full_name: string;
        initials: string;
        photo: string | null;
        department: string | null;
        position: string | null;
    } | null;
};

export type CalibrationSpread = {
    label: string;
    tone: BandTone;
    min_percent: number;
    before: number;
    after: number;
};

export type CalibrationBoardData = {
    rows: CalibrationRow[];
    spread: CalibrationSpread[];
    departments: DepartmentCalibration[];
    average: number | null;
    counts: {
        total: number;
        draft: number;
        submitted: number;
        acknowledged: number;
        moved: number;
        held: number;
    };
};

export type Calibrator = { id: number; name: string };

export type CalibrationIndexPageProps = {
    sessions: CalibrationSession[];
    periods: EvaluationPeriodOption[];
    currentPeriodId: number | null;
    departments: { id: number; name: string }[];
    calibrators: Calibrator[];
    can: PerformancePermissions;
    nav: PerformanceNavCounts;
};

export type CalibrationSessionPageProps = {
    session: CalibrationSession;
    board: CalibrationBoardData;
    calibrators: Calibrator[];
    can: PerformancePermissions;
    me: number;
    nav: PerformanceNavCounts;
};

/** One rating move shown on the scorecard. */
export type CalibrationMove = {
    id: number;
    from_label: string | null;
    to_label: string;
    to_band: string;
    reason: string;
    by: string | null;
    session: { hashid: string; name: string } | null;
    created_at: string | null;
};

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
    nav: PerformanceNavCounts;
};

export type PerformanceShowPageProps = {
    evaluation: PerformanceEvaluation;
    result: ScoreResult;
    support: DecisionSupport;
    feedback: FeedbackSummary;
    goals: { items: PerformanceGoal[]; attainment: number | null };
    calibration: {
        adjustments: CalibrationMove[];
        holding: { hashid: string; name: string } | null;
    };
    reviewers: ReviewCandidate[];
    can: PerformancePermissions & { own: boolean };
    nav: PerformanceNavCounts;
};
