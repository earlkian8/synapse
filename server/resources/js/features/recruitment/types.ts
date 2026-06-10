export type EmploymentType =
    | 'regular'
    | 'probationary'
    | 'contractual'
    | 'part_time';

export type PostingStatus = 'draft' | 'open' | 'closed' | 'filled';

export type Stage =
    | 'applied'
    | 'screening'
    | 'interview'
    | 'offer'
    | 'hired'
    | 'rejected';

export type InterviewMode = 'onsite' | 'online' | 'phone';
export type InterviewResult = 'pending' | 'passed' | 'failed';
export type ApplicantSource =
    | 'website'
    | 'referral'
    | 'linkedin'
    | 'agency'
    | 'walk_in'
    | 'other';

export type SortDirection = 'asc' | 'desc';

export type DepartmentRef = { id: number; name: string; code: string };
export type PositionRef = { id: number; title: string };

export type ManagedPosting = {
    id: number;
    title: string;
    description: string | null;
    requirements: string | null;
    employment_type: EmploymentType;
    openings: number;
    status: PostingStatus;
    closing_date: string | null;
    department: DepartmentRef | null;
    position: PositionRef | null;
    posted_by: string | null;
    department_id: number | null;
    position_id: number | null;
    applications_count?: number;
    open_count?: number | null;
    hired_count?: number | null;
    created_human: string | null;
};

export type Applicant = {
    id: number;
    first_name: string;
    last_name: string;
    full_name: string;
    initials: string;
    email: string | null;
    phone: string | null;
    headline: string | null;
    source: ApplicantSource;
    resume_url: string | null;
    notes: string | null;
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

export type Application = {
    id: number;
    stage: Stage;
    rating: number | null;
    expected_salary: string | null;
    cover_note: string | null;
    rejected_reason: string | null;
    is_hired: boolean;
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
    offers: number;
    interviews_upcoming: number;
    hired_this_month: number;
};

export type PostingOptions = {
    departments: DepartmentRef[];
    positions: { id: number; title: string; department_id: number | null }[];
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
    options: PipelineOptions;
    can: RecruitmentPermissions;
};
