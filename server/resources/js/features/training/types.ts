export type ProgramStatus = 'upcoming' | 'ongoing' | 'completed';

export type TrainingEnrollmentStatus = 'enrolled' | 'completed' | 'dropped';

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

export type TrainingPermissions = { manage: boolean };

export type EnrollableEmployee = {
    id: number;
    full_name: string;
    employee_no: string;
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
    can: TrainingPermissions;
};
