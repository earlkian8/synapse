export type ProgramStatus = 'upcoming' | 'ongoing' | 'completed';

export type TrainingEnrollmentStatus = 'enrolled' | 'completed' | 'dropped';

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
    ai_insights: TrainingInsight | null;
    enrollments_count: number;
    active_count: number;
    completed_count: number;
    seats_remaining: number | null;
    is_full: boolean;
    enrollments?: TrainingEnrollment[];
};

export type TrainingStats = {
    programs: number;
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

export type TrainingInsightResult = TrainingInsight | TrainingInsightUnavailable;

export type TrainingPermissions = { manage: boolean };

export type EnrollableEmployee = {
    id: number;
    full_name: string;
    employee_no: string;
    department: string | null;
};

export type TrainingIndexPageProps = {
    programs: TrainingProgram[];
    archived: TrainingProgram[];
    stats: TrainingStats;
    can: TrainingPermissions;
};

export type TrainingShowPageProps = {
    program: TrainingProgram;
    enrollable: EnrollableEmployee[];
    analytics: TrainingAnalytics;
    ai_available: boolean;
    can: TrainingPermissions;
};
