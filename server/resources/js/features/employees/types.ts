export type EmploymentStatus =
    | 'active'
    | 'on_leave'
    | 'suspended'
    | 'resigned'
    | 'terminated';

export type EmployeeStatus = EmploymentStatus | 'archived';

export type EmploymentType =
    | 'regular'
    | 'probationary'
    | 'contractual'
    | 'part_time';

export type SortDirection = 'asc' | 'desc';

export type DepartmentRef = { id: number; name: string; code: string };
export type PositionRef = { id: number; title: string };
export type ManagerRef = { id: number; full_name: string; employee_no: string };
export type ScheduleRef = { id: number; name: string };
export type UserRef = { id: number; email: string };

export type EmployeeDocument = {
    id: number;
    title: string;
    type: string;
    url: string | null;
    uploaded_by?: string | null;
    created_human: string | null;
};

export type EmployeeCertification = {
    id: number;
    name: string;
    issuer: string | null;
    issued_date: string | null;
    expiry_date: string | null;
    is_expired: boolean;
    url: string | null;
};

export type EmployeePromotion = {
    id: number;
    from_position: string | null;
    to_position: string | null;
    from_salary: string | null;
    to_salary: string | null;
    effective_date: string | null;
    reason: string | null;
};

export type ManagedEmployee = {
    id: number;
    employee_no: string;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    suffix: string | null;
    full_name: string;
    initials: string;
    birth_date: string | null;
    gender: string | null;
    civil_status: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    photo: string | null;

    department: DepartmentRef | null;
    position: PositionRef | null;
    manager: ManagerRef | null;
    work_schedule: ScheduleRef | null;
    user: UserRef | null;

    department_id: number | null;
    position_id: number | null;
    manager_id: number | null;
    work_schedule_id: number | null;
    user_id: number | null;

    employment_type: EmploymentType;
    employment_status: EmploymentStatus;
    status: EmployeeStatus;
    date_hired: string | null;
    date_regularized: string | null;
    tenure_human: string | null;

    basic_salary: string | null;
    bank_name: string | null;
    bank_account_no: string | null;
    tin: string | null;
    sss_no: string | null;
    philhealth_no: string | null;
    pagibig_no: string | null;

    documents_count?: number;
    certifications_count?: number;

    created_human: string | null;
    deleted_at: string | null;
};

/** The full record fetched for the detail drawer (includes sub-records). */
export type EmployeeDetail = ManagedEmployee & {
    documents: EmployeeDocument[];
    certifications: EmployeeCertification[];
    promotions: EmployeePromotion[];
};

export type EmployeeStats = {
    total: number;
    active: number;
    regular: number;
    probationary: number;
    on_leave: number;
    new_this_month: number;
    archived: number;
};

export type EmployeeOptions = {
    departments: DepartmentRef[];
    positions: { id: number; title: string; department_id: number | null }[];
    schedules: ScheduleRef[];
    managers: ManagerRef[];
    users: { id: number; full_name: string; email: string }[];
};

export type EmployeePermissions = {
    create: boolean;
    update: boolean;
    delete: boolean;
    restore: boolean;
    forceDelete: boolean;
    export: boolean;
    manageDocuments: boolean;
};

export type EmployeesFilters = {
    search: string;
    status: string;
    type: string;
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

export type EmployeesPageProps = {
    employees: Paginated<ManagedEmployee>;
    stats: EmployeeStats;
    options: EmployeeOptions;
    can: EmployeePermissions;
    filters: EmployeesFilters;
};

export type BulkEmployeeAction =
    | 'archive'
    | 'restore'
    | 'delete'
    | 'set-status';
