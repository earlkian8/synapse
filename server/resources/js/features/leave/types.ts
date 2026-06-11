export type LeaveStatus = 'pending' | 'approved' | 'rejected' | 'cancelled';

export type HalfDayPeriod = 'morning' | 'afternoon';

export type LeaveTypeRef = {
    id: number;
    name: string;
    code: string;
    color: string;
    is_paid?: boolean;
    allow_half_day?: boolean;
    requires_approval?: boolean;
};

export type LeaveEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    department: { id: number; name: string } | null;
    position: { id: number; title: string } | null;
};

export type BalanceSnapshot = {
    leave_type_id: number;
    name: string;
    code: string;
    color: string;
    entitled: number;
    used: number;
    pending: number;
    remaining: number;
    is_custom: boolean;
};

export type LeaveRequest = {
    id: number;
    hashid: string;
    status: LeaveStatus;
    start_date: string | null;
    end_date: string | null;
    days: number;
    is_half_day: boolean;
    half_day_period: HalfDayPeriod | null;
    reason: string | null;
    review_note: string | null;
    reviewed_at: string | null;
    employee: LeaveEmployee | null;
    type: LeaveTypeRef | null;
    filer?: string | null;
    reviewer?: string | null;
    balance?: BalanceSnapshot | null;
    created_at: string | null;
    created_human: string | null;
};

export type LeaveStats = {
    pending: number;
    on_leave_today: number;
    upcoming: number;
    days_this_month: number;
};

export type DepartmentRef = { id: number; name: string };
export type EmployeeOption = {
    id: number;
    full_name: string;
    employee_no: string;
};

export type LeavePermissions = { request: boolean; manage: boolean };

export type LeaveFilters = {
    search: string;
    status: string;
    type: number | null;
    department: number | null;
};

export type LeaveIndexPageProps = {
    requests: LeaveRequest[];
    stats: LeaveStats;
    options: {
        types: LeaveTypeRef[];
        departments: DepartmentRef[];
        employees: EmployeeOption[];
    };
    can: LeavePermissions;
    filters: LeaveFilters;
};

// ── Balances ─────────────────────────────────────────────────────────────────

export type BalanceType = {
    id: number;
    name: string;
    code: string;
    color: string;
    default_days: number;
};

export type EmployeeBalance = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    department: string | null;
    balances: BalanceSnapshot[];
};

export type BalancesFilters = {
    search: string;
    department: number | null;
};

export type BalancesPageProps = {
    types: BalanceType[];
    employees: EmployeeBalance[];
    year: number;
    years: number[];
    options: { departments: DepartmentRef[] };
    can: { manage: boolean };
    filters: BalancesFilters;
};
