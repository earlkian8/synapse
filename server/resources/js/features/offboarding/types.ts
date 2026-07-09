export type CaseStatus = 'initiated' | 'clearance' | 'completed' | 'cancelled';

export type OffboardingType =
    | 'resignation'
    | 'termination'
    | 'retirement'
    | 'end_of_contract';

/** A clearance item's own state. */
export type ClearanceStatus = 'pending' | 'cleared' | 'flagged';

/** The case-level clearance state, derived from the items. */
export type DerivedClearanceStatus = 'pending' | 'in_progress' | 'cleared';

export type EmploymentType =
    | 'regular'
    | 'probationary'
    | 'contractual'
    | 'part_time';

export type EmploymentStatus =
    | 'active'
    | 'on_leave'
    | 'suspended'
    | 'resigned'
    | 'terminated';

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
};
