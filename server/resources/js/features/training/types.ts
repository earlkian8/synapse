export type ProgramStatus = 'upcoming' | 'ongoing' | 'completed';

export type TrainingEnrollmentStatus =
    'requested' | 'enrolled' | 'completed' | 'dropped' | 'declined';

/** The statuses of someone on the roster — what HR grades. */
export type RosterStatus = 'enrolled' | 'completed' | 'dropped';

/** Who may put themselves on a program (ADR 0074). */
export type EnrollmentMode = 'closed' | 'open' | 'approval';

export type AttendanceStatus = 'present' | 'late' | 'absent' | 'excused';

export type CostCategory =
    | 'trainer_fee'
    | 'venue'
    | 'materials'
    | 'travel'
    | 'meals'
    | 'certification'
    | 'other';

/** A certificate's standing today (ADR 0075). */
export type CertificateStanding = 'valid' | 'expiring' | 'expired' | 'renewed';

/** How the programs overview is laid out. */
export type ProgramsView = 'table' | 'grid';

/** A bulk action applied to selected roster rows. */
export type BulkAction = 'complete' | 'drop' | 'enroll' | 'remove';

export type TrainingEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    position: string | null;
    department: string | null;
};

export type TrainingEnrollment = {
    id: number;
    status: TrainingEnrollmentStatus;
    score: number | null;
    completed_at: string | null;
    enrolled_on: string | null;
    remarks: string | null;
    requested_on: string | null;
    decided_at: string | null;
    decision_note: string | null;
    certificate?: {
        id: number;
        certificate_no: string | null;
        expiry_date: string | null;
    } | null;
    employee?: TrainingEmployee | null;
    program?: {
        id: number;
        hashid: string;
        name: string;
        provider: string | null;
        status: ProgramStatus;
    } | null;
};

export type TrainingProgram = {
    id: number;
    hashid: string;
    name: string;
    description: string | null;
    provider: string | null;
    start_date: string | null;
    end_date: string | null;
    capacity: number | null;
    status: ProgramStatus;
    is_archived: boolean;
    enrollment_mode: EnrollmentMode;
    issues_certificate: boolean;
    certificate_validity_months: number | null;
    budget: number | null;
    ai_insights: TrainingInsight | null;
    enrollments_count: number;
    active_count: number;
    completed_count: number;
    seats_remaining: number | null;
    is_full: boolean;
    pending_count: number;
    sessions_count: number;
    enrollments?: TrainingEnrollment[];
};

export type TrainingStats = {
    programs: number;
    requests: number;
    ongoing: number;
    upcoming: number;
    enrolled: number;
    completed: number;
};

/** Effectiveness analytics for one program, derived server-side from its roster. */
export type TrainingAnalytics = {
    total: number;
    completed: number;
    dropped: number;
    enrolled: number;
    completion_rate: number | null;
    average_score: number | null;
    at_risk: number;
};

/** A successful LLM effectiveness read for a program. */
export type TrainingInsight = {
    available: true;
    headline: string;
    summary: string;
    whats_working: string[];
    concerns: string[];
    recommendations: string[];
    follow_up: string[];
    generated_at: string;
};

export type TrainingInsightUnavailable = {
    available: false;
    reason: string;
    retryable: boolean;
};

export type TrainingInsightResult =
    TrainingInsight | TrainingInsightUnavailable;

export type TrainingPermissions = { manage: boolean };

export type EnrollableEmployee = {
    id: number;
    full_name: string;
    employee_no: string;
    department: string | null;
};

/** The counts the section switcher shows. */
export type TrainingNavCounts = { requests: number };

/** One session of a program, on the organisation's clock. */
export type TrainingSession = {
    id: number;
    title: string | null;
    session_date: string;
    starts_at: string;
    ends_at: string;
    location: string | null;
    notes: string | null;
    is_held: boolean;
    /** Each mark taken at the session, for the attendance sheet. */
    marks?: { enrollment_id: number; status: AttendanceStatus }[];
    tally?: Record<AttendanceStatus, number>;
};

/** Attended / counted, and the rate (null until anything is counted). */
export type AttendanceRate = {
    attended: number;
    counted: number;
    rate: number | null;
};

export type ProgramAttendance = AttendanceRate & {
    by_enrollment: Record<number, AttendanceRate>;
    sessions: number;
    held: number;
};

export type TrainingCost = {
    id: number;
    category: CostCategory;
    description: string | null;
    amount: number;
    incurred_on: string;
    recorded_by?: string | null;
};

/** A program's money against its budget. */
export type ProgramMoney = {
    budget: number | null;
    spent: number;
    remaining: number | null;
    over_budget: boolean;
    used_percent: number | null;
    per_participant: number | null;
    per_completion: number | null;
    entries: number;
    by_category: Partial<Record<CostCategory, number>>;
};

export type TrainingCertificate = {
    id: number;
    name: string;
    issuer: string | null;
    certificate_no: string | null;
    issued_date: string | null;
    expiry_date: string | null;
    standing: CertificateStanding;
    score: number | null;
    program: { hashid: string; name: string; archived: boolean } | null;
    employee?: {
        id: number;
        full_name: string;
        initials: string;
        employee_no: string;
        photo: string | null;
        department: string | null;
        position: string | null;
    } | null;
};

export type TrainingIndexPageProps = {
    programs: TrainingProgram[];
    archived: TrainingProgram[];
    requests: TrainingEnrollment[];
    stats: TrainingStats;
    can: TrainingPermissions;
    nav: TrainingNavCounts;
};

export type TrainingShowPageProps = {
    program: TrainingProgram;
    requests: TrainingEnrollment[];
    sessions: TrainingSession[];
    attendance: ProgramAttendance;
    costs: TrainingCost[];
    money: ProgramMoney;
    enrollable: EnrollableEmployee[];
    analytics: TrainingAnalytics;
    ai_available: boolean;
    can: TrainingPermissions;
    nav: TrainingNavCounts;
};

/** One of the person's own enrollments, on My training. */
export type MyEnrollment = {
    id: number;
    status: TrainingEnrollmentStatus;
    score: number | null;
    completed_at: string | null;
    requested_on: string | null;
    decision_note: string | null;
    attendance: AttendanceRate;
    can_withdraw: boolean;
    certificate_id: number | null;
    program: {
        hashid: string;
        name: string;
        provider: string | null;
        description: string | null;
        start_date: string | null;
        end_date: string | null;
        status: ProgramStatus;
    };
};

/** A session of a program the person holds a seat on. */
export type MySession = {
    id: number;
    title: string | null;
    session_date: string;
    starts_at: string;
    ends_at: string;
    location: string | null;
    notes: string | null;
    program: { name: string | null; provider: string | null };
};

export type MyTrainingTab = 'sessions' | 'programs' | 'certificates';

export type MyTrainingPageProps = {
    enrollments: MyEnrollment[];
    sessions: MySession[];
    certificates: TrainingCertificate[];
    tab: MyTrainingTab | null;
    has_employee: boolean;
    nav: TrainingNavCounts;
};

export type CatalogProgram = TrainingProgram & {
    mine: {
        id: number;
        status: TrainingEnrollmentStatus;
        decision_note: string | null;
    } | null;
};

export type CatalogPageProps = {
    programs: CatalogProgram[];
    has_employee: boolean;
    nav: TrainingNavCounts;
};

/** A session on the training calendar. */
export type ScheduledSession = {
    id: number;
    title: string | null;
    session_date: string;
    starts_at: string;
    ends_at: string;
    location: string | null;
    is_held: boolean;
    marked: number;
    program: {
        id: number;
        hashid: string;
        name: string;
        provider: string | null;
    };
};

export type SchedulePageProps = {
    month: string;
    today: string;
    sessions: ScheduledSession[];
    unscheduled: {
        hashid: string;
        name: string;
        start_date: string | null;
        end_date: string | null;
    }[];
    can: TrainingPermissions;
    nav: TrainingNavCounts;
};

export type CertificatesPageProps = {
    certificates: TrainingCertificate[];
    stats: Record<CertificateStanding, number>;
    expiring_days: number;
    programs: {
        hashid: string;
        name: string;
        status: ProgramStatus;
        issues_certificate: boolean;
    }[];
    standing: CertificateStanding | null;
    can: TrainingPermissions;
    nav: TrainingNavCounts;
};

export type CertificatePageProps = {
    certificate: TrainingCertificate;
    organization: { name: string | null; logo: string | null };
    completed_on: string | null;
    back: string;
};

export type BudgetSummary = {
    year: number;
    budget: number | null;
    notes: string | null;
    spent: number;
    remaining: number | null;
    used_percent: number | null;
    allocated: number;
    by_category: Partial<Record<CostCategory, number>>;
    by_month: number[];
    programs: {
        id: number;
        hashid: string;
        name: string;
        archived: boolean;
        status: ProgramStatus;
        budget: number | null;
        spent: number;
        spent_total: number;
    }[];
};

export type BudgetCost = TrainingCost & {
    program: { hashid: string | null; name: string | null; archived: boolean };
};

export type BudgetPageProps = {
    summary: BudgetSummary;
    years: number[];
    costs: BudgetCost[];
    can: TrainingPermissions;
    nav: TrainingNavCounts;
};
