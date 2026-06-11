export type CaseStatus = 'pending' | 'in_progress' | 'completed' | 'cancelled';

export type TaskStatus = 'pending' | 'in_progress' | 'done' | 'skipped';

export type TaskCategory =
    | 'paperwork'
    | 'equipment'
    | 'access'
    | 'orientation'
    | 'training'
    | 'compliance'
    | 'other';

export type EmploymentType =
    | 'regular'
    | 'probationary'
    | 'contractual'
    | 'part_time';

export type CaseProgress = {
    total: number;
    done: number;
    resolved: number;
    overdue: number;
    percent: number;
};

export type CaseEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    employment_type: EmploymentType | null;
    department: { id: number; name: string } | null;
    position: { id: number; title: string } | null;
    date_hired: string | null;
};

export type OnboardingTask = {
    id: number;
    title: string;
    description: string | null;
    category: TaskCategory;
    status: TaskStatus;
    is_resolved: boolean;
    is_overdue: boolean;
    assignee: { id: number; full_name: string } | null;
    completer: string | null;
    assigned_to: number | null;
    due_date: string | null;
    due_human: string | null;
    completed_at: string | null;
    completed_human: string | null;
    sort_order: number;
};

export type OnboardingCase = {
    id: number;
    hashid: string;
    status: CaseStatus;
    is_active: boolean;
    start_date: string | null;
    target_end_date: string | null;
    completed_at: string | null;
    notes: string | null;
    progress: CaseProgress;
    employee: CaseEmployee | null;
    program: { id: number; name: string } | null;
    tasks?: OnboardingTask[];
    created_human: string | null;
    updated_human: string | null;
};

export type ProgramTaskDraft = {
    id?: number;
    title: string;
    description: string | null;
    category: TaskCategory;
    due_offset_days: number;
    sort_order: number;
};

export type OnboardingProgram = {
    id: number;
    hashid: string;
    name: string;
    description: string | null;
    employment_type: EmploymentType | null;
    is_default: boolean;
    is_active: boolean;
    department: { id: number; name: string } | null;
    department_id: number | null;
    tasks?: ProgramTaskDraft[];
    tasks_count?: number;
    cases_count?: number;
    created_human: string | null;
};

export type OnboardingStats = {
    active: number;
    overdue_tasks: number;
    completing_soon: number;
    completed_this_month: number;
};

export type DepartmentRef = { id: number; name: string };
export type EmployeeOption = {
    id: number;
    full_name: string;
    employee_no: string;
};
export type ProgramOption = {
    id: number;
    name: string;
    tasks_count: number;
    is_default: boolean;
};
export type AssigneeRef = { id: number; full_name: string };

export type OnboardingPermissions = {
    manage: boolean;
    managePrograms: boolean;
};

export type OnboardingFilters = {
    search: string;
    status: string;
    department: number | null;
};

export type IndexPageProps = {
    cases: OnboardingCase[];
    stats: OnboardingStats;
    options: {
        departments: DepartmentRef[];
        programs: ProgramOption[];
        employees: EmployeeOption[];
    };
    can: OnboardingPermissions;
    filters: OnboardingFilters;
};

export type CasePageProps = {
    case: OnboardingCase;
    options: { assignees: AssigneeRef[] };
    can: OnboardingPermissions;
};

export type ProgramsPageProps = {
    programs: OnboardingProgram[];
    options: { departments: DepartmentRef[] };
    can: { managePrograms: boolean };
};
