export type EmploymentType =
    'regular' | 'probationary' | 'contractual' | 'part_time';

export type PostingStatus = 'draft' | 'open' | 'closed' | 'filled';

/** What a stage means to the business logic — never its (arbitrary) name. */
export type StageKind = 'open' | 'won' | 'lost';

/** One step in a pipeline. Custom-named; kind + position drive behaviour. */
export type PipelineStage = {
    id: number;
    name: string;
    kind: StageKind;
    position: number;
};

/** A tenant-defined hiring process a posting is assigned to. */
export type Pipeline = {
    id: number;
    hashid: string;
    name: string;
    stages: PipelineStage[] | null;
};

/** A pipeline as a lightweight select option (the posting form's picker). */
export type PipelineOption = { id: number; name: string; is_default: boolean };

export type ScreeningQuestion = { id: number; label: string };

export type InterviewMode = 'onsite' | 'online' | 'phone';
export type InterviewResult = 'pending' | 'passed' | 'failed';
export type ApplicantSource =
    'website' | 'referral' | 'linkedin' | 'agency' | 'walk_in' | 'other';

export type SortDirection = 'asc' | 'desc';

/** How the postings index is laid out: a dense table or a card grid. */
export type PostingsView = 'table' | 'grid';

/** How the pipeline is laid out: the sequential board, or a dense table. */
export type PipelineView = 'board' | 'table';

export type DepartmentRef = { id: number; name: string; code: string };
export type PositionRef = { id: number; title: string };

export type ManagedPosting = {
    id: number;
    hashid: string;
    title: string;
    description: string | null;
    requirements: string | null;
    min_years_experience: number | null;
    skills: string[];
    requires_resume: boolean;
    use_fit_scoring: boolean;
    employment_type: EmploymentType;
    openings: number;
    status: PostingStatus;
    closing_date: string | null;
    closes_human: string | null;
    days_to_close: number | null;
    is_expired: boolean;
    is_open: boolean;
    apply_url: string | null;
    department: DepartmentRef | null;
    position: PositionRef | null;
    posted_by: string | null;
    pipeline: Pipeline | null;
    screening_questions?: ScreeningQuestion[];
    department_id: number | null;
    position_id: number | null;
    recruitment_pipeline_id: number;
    applications_count?: number;
    open_count?: number | null;
    hired_count?: number | null;
    created_human: string | null;
};

export type ApplicantDocument = {
    id: number;
    title: string;
    type: string;
    url: string | null;
    uploaded_human: string | null;
};

export type Applicant = {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    initials: string;
    email: string | null;
    phone: string | null;
    current_location: string | null;
    headline: string | null;
    linkedin_url: string | null;
    portfolio_url: string | null;
    years_experience: number | null;
    source: ApplicantSource;
    resume_url: string | null;
    notes: string | null;
    documents?: ApplicantDocument[];
    applications_count?: number;
    created_human?: string | null;
};

export type Interview = {
    id: number;
    scheduled_at: string | null;
    scheduled_human: string | null;
    scheduled_label: string | null;
    mode: InterviewMode;
    location: string | null;
    notes: string | null;
    result: InterviewResult;
    feedback: string | null;
    interviewer: string | null;
    interviewer_id: number | null;
};

export type EmployeeRef = {
    id: number;
    full_name: string;
    employee_no: string;
};

/** A band describing how strong a candidate's fit is. */
export type FitBand = 'strong' | 'promising' | 'fair' | 'weak';

export type FitBreakdownItem = {
    key: string;
    label: string;
    points: number;
    max: number;
    detail: string;
};

/** The automatic fit score the ApplicantScorer assigns to an application. */
export type Fit = {
    value: number;
    band: FitBand;
    breakdown: FitBreakdownItem[];
};

/** A candidate's standing among the still-active applicants for a posting. */
export type FitRank = { position: number; total: number };

/** The recommended next step surfaced in the decision panel. `stage_id` only
 *  carries a value when `action` is `advance`. */
export type Recommendation = {
    action: 'advance' | 'hire' | 'reject' | null;
    stage_id: number | null;
    label: string;
    tone: 'positive' | 'neutral' | 'caution';
    hint: string;
};

/** The LLM-generated candidate read, available once HR generates it. */
export type ApplicantInsight = {
    available: true;
    headline: string;
    summary: string;
    strengths: string[];
    concerns: string[];
    document_insights: string;
    interview_questions: string[];
    recommendation: string;
    documents_read: string[];
    generated_at: string;
};

/** Why insights couldn't be produced (key missing, quota, parse error). */
export type ApplicantInsightUnavailable = {
    available: false;
    reason: string;
    retryable: boolean;
};

/** The insights endpoint's payload: a usable read, or a reason it's unavailable. */
export type ApplicantInsightResult =
    ApplicantInsight | ApplicantInsightUnavailable;

/** One of the candidate's applications to another posting. */
export type OtherApplication = {
    id: number;
    stage: string;
    stage_kind: StageKind;
    rating: number | null;
    posting: string | null;
    posting_status: PostingStatus | null;
    applied_human: string | null;
};

export type Application = {
    id: number;
    stage: string;
    stage_id: number;
    stage_kind: StageKind;
    rating: number | null;
    expected_salary: string | null;
    cover_note: string | null;
    rejected_reason: string | null;
    screening_answers: Record<number, boolean> | null;
    is_hired: boolean;
    fit: Fit | null;
    fit_rank: FitRank | null;
    recommendation: Recommendation | null;
    other_applications: OtherApplication[] | null;
    ai_insights: ApplicantInsight | null;
    applicant: Applicant | null;
    job_posting?: { id: number; title: string } | null;
    hired_employee?: EmployeeRef | null;
    interviews?: Interview[];
    interviews_count?: number;
    applicant_id: number;
    hired_employee_id: number | null;
    applied_at: string | null;
    applied_human: string | null;
    age_days: number | null;
    decided_at: string | null;
    updated_human: string | null;
};

/** The full application fetched for the detail drawer (includes interviews). */
export type ApplicationDetail = Application & {
    interviews: Interview[];
};

export type RecruitmentStats = {
    open_postings: number;
    total_applicants: number;
    in_pipeline: number;
    final_stage: number;
    interviews_upcoming: number;
    hired_this_month: number;
};

/** The standout candidate a pipeline insight points to. */
export type InsightTop = {
    name: string;
    fit: number;
    stage?: string;
};

/** Decision-support metrics for a single pipeline stage. */
export type StageInsight = {
    count: number;
    avg_fit: number | null;
    strong: number;
    ready: number;
    stalled: number;
    next_stage: string | null;
    top: InsightTop | null;
};

/** Pipeline-wide decision support across all still-active candidates. */
export type OverallInsight = {
    total: number;
    active: number;
    avg_fit: number | null;
    strong: number;
    ready: number;
    stalled: number;
    hired: number;
    rejected: number;
    conversion: number | null;
    top: InsightTop | null;
};

/** The full pipeline insights payload, built server-side from fit scores —
 *  `stages` is keyed by stage id (stage names are arbitrary per pipeline). */
export type PipelineInsightsData = {
    overall: OverallInsight;
    stages: Record<number, StageInsight>;
};

export type PostingOptions = {
    departments: DepartmentRef[];
    positions: { id: number; title: string; department_id: number | null }[];
    pipelines: PipelineOption[];
};

export type InterviewerRef = { id: number; full_name: string };

export type PipelineOptions = {
    interviewers: InterviewerRef[];
    applicants: Applicant[];
};

export type RecruitmentPermissions = {
    create: boolean;
    update: boolean;
    delete: boolean;
    managePipeline: boolean;
    scheduleInterviews: boolean;
    hire: boolean;
    export: boolean;
};

export type PostingsFilters = {
    search: string;
    status: string;
    department: number | null;
    sort: string;
    direction: SortDirection;
    per_page: number;
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type PaginationMeta = {
    current_page: number;
    from: number | null;
    to: number | null;
    last_page: number;
    per_page: number;
    total: number;
    links: PaginationLink[];
};

export type Paginated<T> = {
    data: T[];
    meta: PaginationMeta;
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
};

export type PostingsPageProps = {
    postings: Paginated<ManagedPosting>;
    stats: RecruitmentStats;
    options: PostingOptions;
    can: RecruitmentPermissions;
    filters: PostingsFilters;
};

export type PipelinePageProps = {
    posting: ManagedPosting;
    applications: Application[];
    insights: PipelineInsightsData;
    options: PipelineOptions;
    can: RecruitmentPermissions;
};

/** How the pipeline candidates are ordered client-side. */
export type PipelineSort =
    'default' | 'fit' | 'rating' | 'recent' | 'oldest' | 'name';
