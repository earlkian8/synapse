export type CaseStatus = 'initiated' | 'clearance' | 'completed' | 'cancelled';

export type OffboardingType =
    'resignation' | 'termination' | 'retirement' | 'end_of_contract';

/** A clearance item's own state. */
export type ClearanceStatus = 'pending' | 'cleared' | 'flagged';

/** The case-level clearance state, derived from the items. */
export type DerivedClearanceStatus = 'pending' | 'in_progress' | 'cleared';

export type EmploymentType =
    'regular' | 'probationary' | 'contractual' | 'part_time';

export type EmploymentStatus =
    'active' | 'on_leave' | 'suspended' | 'resigned' | 'terminated';

export type ClearanceSummary = {
    total: number;
    cleared: number;
    flagged: number;
    pending: number;
    percent: number;
    status: DerivedClearanceStatus;
};

export type CaseEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    employment_type: EmploymentType | null;
    employment_status: EmploymentStatus | null;
    department: { id: number; name: string } | null;
    position: { id: number; title: string } | null;
    date_hired: string | null;
};

export type ClearanceItem = {
    id: number;
    item: string;
    status: ClearanceStatus;
    is_cleared: boolean;
    remarks: string | null;
    department: { id: number; name: string } | null;
    cleared_by: string | null;
    department_id: number | null;
    cleared_at: string | null;
    cleared_human: string | null;
    sort_order: number;
};

export type OffboardingCase = {
    id: number;
    hashid: string;
    type: OffboardingType;
    status: CaseStatus;
    is_active: boolean;
    notice_date: string | null;
    last_working_day: string | null;
    reason: string | null;
    completed_at: string | null;
    clearance: ClearanceSummary;
    /** Name of the clearance template that seeded this case, when known. */
    program?: string | null;
    employee: CaseEmployee | null;
    items?: ClearanceItem[];
    created_human: string | null;
    updated_human: string | null;
};

export type DepartmentRef = { id: number; name: string };

export type EmployeeOption = {
    id: number;
    full_name: string;
    employee_no: string;
};

export type OffboardingPermissions = {
    manage: boolean;
};

// ── Clearance templates (offboarding programs) ──────────────────────────────

/** One blueprint sign-off row inside a template's editor. */
export type ProgramItemDraft = {
    item: string;
    department_id: number | null;
    use_employee_department: boolean;
    sort_order: number;
};

export type OffboardingProgram = {
    id: number;
    hashid: string;
    name: string;
    description: string | null;
    exit_type: OffboardingType | null;
    is_default: boolean;
    is_active: boolean;
    department: DepartmentRef | null;
    department_id: number | null;
    items?: (ProgramItemDraft & { id: number; department: string | null })[];
    items_count?: number;
    cases_count?: number;
    created_human: string | null;
};

/** A lightweight template option for pickers (start-exit, apply-to-case). */
export type ProgramOption = {
    id: number;
    name: string;
    is_default: boolean;
    items_count: number;
};

export type ProgramsPageProps = {
    programs: OffboardingProgram[];
    options: { departments: DepartmentRef[] };
    can: { managePrograms: boolean };
};

export type OffboardingFilters = {
    search: string;
    status: string;
    type: string | null;
    department: number | null;
};

export type OffboardingStats = {
    active: number;
    flagged_items: number;
    leaving_soon: number;
    completed_this_month: number;
};

export type IndexPageProps = {
    nav: OffboardingNavCounts;
    cases: OffboardingCase[];
    stats: OffboardingStats;
    options: {
        departments: DepartmentRef[];
        programs: ProgramOption[];
        employees: EmployeeOption[];
    };
    can: OffboardingPermissions;
    filters: OffboardingFilters;
};

export type CasePageProps = {
    case: OffboardingCase;
    options: { departments: DepartmentRef[]; programs: ProgramOption[] };
    can: OffboardingPermissions;
    interview: CaseInterview | null;
    documents: { certificate_ready: boolean; certificate_no: string | null };
    /** The resignation the employee filed, when the exit began as one. */
    resignation: { filed_on: string; proposed_last_day: string | null } | null;
    scale: Record<string, string>;
    tab: 'clearance' | 'interview';
    nav: OffboardingNavCounts;
    /** The leaver's sign-in here, while it still opens this workspace. */
    access: CaseAccess | null;
};

/** Whether the leaver can still sign in here, and whether the viewer may end it (ADR 0089). */
export type CaseAccess = {
    user_id: number;
    /** Their account belongs to another workspace too, so removing it keeps the account. */
    shared: boolean;
    can_remove: boolean;
    /** Why the viewer may not remove it, worded to be shown as it is. */
    reason: string | null;
};

// ── Resignations, exit interviews and documents (ADRs 0076, 0077) ───────────

/** The counts the section switcher shows. */
export type OffboardingNavCounts = { resignations: number };

export type ResignationStatus =
    'pending' | 'accepted' | 'declined' | 'withdrawn';

/** One resignation as its own employee sees it. */
export type MyResignation = {
    id: string;
    status: ResignationStatus;
    proposed_last_day: string;
    reason: string | null;
    filed_on: string | null;
    notice_days: number;
    decided_on: string | null;
    decision_note: string | null;
    decided_by: string | null;
};

export type InterviewStatus = 'pending' | 'submitted' | 'waived';

export type InterviewState = {
    status: InterviewStatus;
    sent_on: string | null;
    submitted_on: string | null;
    can_answer: boolean;
};

/** The leaver's own exit, as My resignation shows it. */
export type MyExitCase = {
    id: string;
    type: OffboardingType;
    status: CaseStatus;
    notice_date: string | null;
    last_working_day: string | null;
    completed_on: string | null;
    clearance: {
        total: number;
        cleared: number;
        flagged: number;
        status: DerivedClearanceStatus;
    };
    items: {
        id: number;
        item: string;
        department: string | null;
        status: ClearanceStatus;
        remarks: string | null;
        cleared_on: string | null;
    }[];
    certificate: { ready: boolean; number: string | null };
};

export type MyExitPageProps = {
    has_employee: boolean;
    can_resign: boolean;
    notice_days: number;
    today: string;
    resignations: MyResignation[];
    exit: MyExitCase | null;
    interview: InterviewState | null;
    nav: OffboardingNavCounts;
};

export type QuestionType = 'choice' | 'rating' | 'yes_no' | 'text';

export type InterviewQuestion = {
    key: string;
    type: QuestionType;
    label: string;
    required: boolean;
    group?: string;
    options?: Record<string, string>;
};

export type InterviewAnswers = Record<string, string | number>;

export type ExitInterviewPageProps = {
    interview: InterviewState & {
        questions: InterviewQuestion[];
        answers: InterviewAnswers | null;
        scale: Record<string, string>;
    };
    nav: OffboardingNavCounts;
};

/** A resignation in HR's list. */
export type ResignationRow = {
    id: string;
    status: ResignationStatus;
    employee: {
        id: number;
        full_name: string;
        initials: string;
        employee_no: string;
        photo: string | null;
        department: string | null;
        department_id: number | null;
        position: string | null;
    };
    filed_on: string;
    proposed_last_day: string;
    notice_days: number;
    reason: string | null;
    decided_on: string | null;
    decided_by: string | null;
    decision_note: string | null;
    case_id: string | null;
};

export type ResignationsPageProps = {
    resignations: ResignationRow[];
    stats: {
        pending: number;
        short_notice: number;
        accepted_30d: number;
        withdrawn_30d: number;
    };
    options: { programs: ProgramOption[]; departments: DepartmentRef[] };
    notice_days: number;
    can: OffboardingPermissions;
    nav: OffboardingNavCounts;
};

export type InterviewPeriod = '12m' | '6m' | '3m' | 'all';

export type InterviewSummary = {
    responses: number;
    reasons: { key: string; label: string; count: number; share: number }[];
    ratings: {
        key: string;
        label: string;
        average: number | null;
        count: number;
        distribution: Record<string, number>;
    }[];
    recommend: Record<'yes' | 'maybe' | 'no', number>;
    return: Record<'yes' | 'maybe' | 'no', number>;
    average: number | null;
    sent: number;
    waiting: number;
    waived: number;
    response_rate: number | null;
};

export type InterviewRow = {
    id: number;
    status: InterviewStatus;
    case_id: string;
    exit_type: OffboardingType;
    last_working_day: string | null;
    employee: {
        full_name: string;
        initials: string;
        employee_no: string;
        photo: string | null;
        department: string | null;
    };
    sent_on: string | null;
    submitted_on: string | null;
    reason: string | null;
    average: number | null;
    recommend: 'yes' | 'maybe' | 'no' | null;
};

export type InterviewsPageProps = {
    period: InterviewPeriod;
    summary: InterviewSummary;
    interviews: InterviewRow[];
    nav: OffboardingNavCounts;
};

/** The exit interview on a case page — HR's view, answers included. */
export type CaseInterview = InterviewState & {
    questions: InterviewQuestion[];
    answers: InterviewAnswers | null;
    reminded_on: string | null;
    waived_by: string | null;
    average: number | null;
};
