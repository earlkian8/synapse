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

/** How the pipeline is laid out: the table (the default), or the Kanban board. */
export type PipelineView = 'table' | 'board';

export type DepartmentRef = { id: number; name: string; code: string };
export type PositionRef = { id: number; title: string };

/** What a pay figure is quoted per (ADR 0091). */
export type SalaryPeriod = 'monthly' | 'annual' | 'daily' | 'hourly';

/** Where the work is done. */
export type WorkArrangement = 'onsite' | 'hybrid' | 'remote';

export type ManagedPosting = {
    id: number;
    hashid: string;
    title: string;
    description: string | null;
    requirements: string | null;
    min_years_experience: number | null;
    /** Must-haves (ADR 0097). */
    skills: string[];
    /** Nice-to-haves: each counts half as much as a must-have. */
    preferred_skills: string[];
    requires_resume: boolean;
    use_fit_scoring: boolean;
    employment_type: EmploymentType;
    openings: number;
    salary_min: string | null;
    salary_max: string | null;
    salary_period: SalaryPeriod;
    show_salary: boolean;
    /** "₱25,000–₱35,000 a month", or null when no pay was given. */
    pay_label: string | null;
    asks_expected_salary: boolean;
    work_arrangement: WorkArrangement;
    work_location_id: number | null;
    work_location?: string | null;
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
    /** Whether the résumé's text could be read for the fit score (ADR 0097). */
    resume_read_status?: ResumeReadStatus | null;
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
    /** The `skills` item: how each of the posting's skills was shown. */
    skills?: SkillEvidence[];
    /** The `skills` item: whether the résumé could be read for them. */
    resume?: ResumeReadStatus | 'none';
    /** The `experience` item: the years it judged. */
    years?: number;
    /** The `experience` item: its years were read from the résumé's dates. */
    estimated?: boolean;
};

/** How far reading a résumé's text got (ADR 0097). */
export type ResumeReadStatus = 'pending' | 'read' | 'unreadable';

/**
 * How a candidate shows one skill: by name (`found`), through a skill that
 * proves it (`implied`: TypeScript for JavaScript), through all its parts,
 * spelled one letter off, partly through something related, or not at all.
 */
export type SkillMatchStatus =
    'found' | 'implied' | 'parts' | 'spelling' | 'related' | 'missing';

export type SkillEvidence = {
    /** The skill as the posting names it. */
    skill: string;
    tier: 'must' | 'nice';
    status: SkillMatchStatus;
    /** What stood for it: the alias, the proving or related skill, the slip. */
    via: string | null;
    /** Where it was found: résumé, headline, cover note, documents, notes. */
    source: string | null;
    /** 1 when shown or proven; part of 1 when only related. */
    credit: number;
    /** Whether the skill catalogue knows it (otherwise it is matched by its words). */
    recognised: boolean;
};

/** One skill the ATS knows, as the posting form describes it. */
export type CatalogueSkill = {
    name: string;
    aliases: string[];
    proven_by: string[];
    proven_by_count: number;
    related: string[];
};

/** The skill catalogue (`GET /recruitment/skills`). */
export type SkillCatalogue = {
    skills: CatalogueSkill[];
    /** Words before a skill that don't change which skill it is ("advanced"). */
    qualifiers: string[];
    /** Words after a skill that don't change it ("development", "skills"). */
    generic: string[];
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
    expected_salary_period: SalaryPeriod | null;
    /** "₱30,000 a month" — with the period it was given in, when known. */
    expected_salary_label: string | null;
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
    /** The sites a role can be based at (Company Setup → Locations). */
    locations: { id: number; name: string }[];
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
    /** Your own interviews still waiting on a verdict (ADR 0091). */
    nav: { interviews: number };
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

/** One interview assigned to the signed-in person (ADR 0091). */
export type MyInterview = {
    id: number;
    scheduled_at: string | null;
    scheduled_label: string | null;
    scheduled_human: string | null;
    is_past: boolean;
    mode: InterviewMode;
    location: string | null;
    notes: string | null;
    result: InterviewResult;
    feedback: string | null;
    posting: { title: string | null; department: string | null };
    stage: string | null;
    candidate: {
        name: string;
        initials: string;
        headline: string | null;
        current_location: string | null;
        years_experience: number | null;
        email: string | null;
        phone: string | null;
        linkedin_url: string | null;
        portfolio_url: string | null;
        resume_url: string | null;
        cover_note: string | null;
    };
    screening: { label: string; answer: boolean }[];
};

export type MyInterviewsPageProps = {
    interviews: { pending: MyInterview[]; done: MyInterview[] };
};
