export type AttendanceStatus =
    | 'present'
    | 'late'
    | 'undertime'
    | 'absent'
    | 'on_leave'
    | 'day_off'
    | 'holiday'
    | 'incomplete';

export type PunchType = 'clock_in' | 'clock_out' | 'break_start' | 'break_end';

export type PunchSource = 'web' | 'mobile' | 'kiosk' | 'biometric' | 'manual';

export type AttendanceEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    department: { id: number; name: string } | null;
    position: { id: number; title: string } | null;
};

export type Punch = {
    id: number;
    type: PunchType;
    punched_at: string | null;
    source: PunchSource;
    latitude: number | null;
    longitude: number | null;
    accuracy: number | null;
    photo: string | null;
    note: string | null;
    recorder: string | null;
};

export type AttendanceRecord = {
    id: number | null;
    hashid: string | null;
    work_date: string | null;
    status: AttendanceStatus;
    scheduled_start: string | null;
    scheduled_end: string | null;
    first_in_at: string | null;
    last_out_at: string | null;
    worked_minutes: number;
    break_minutes: number;
    late_minutes: number;
    undertime_minutes: number;
    overtime_minutes: number;
    is_manual: boolean;
    remarks: string | null;
    approval_status: 'pending' | 'approved' | 'rejected' | null;
    approved_at: string | null;
    approver?: string | null;
    employee: AttendanceEmployee | null;
    punches?: Punch[];
};

export type AttendanceStats = {
    present: number;
    late: number;
    absent: number;
    on_leave: number;
    avg_hours: number;
    pending: number;
};

export type DepartmentRef = { id: number; name: string };
export type EmployeeOption = {
    id: number;
    full_name: string;
    employee_no: string;
};

export type AttendancePermissions = { manage: boolean; clock: boolean };

export type AttendanceTab = 'today' | 'weekly' | 'monthly';

export type AttendanceFilters = {
    date: string;
    tab: AttendanceTab;
    search: string;
    status: string;
    department: number | null;
};

// ── Weekly grid ──────────────────────────────────────────────────────────────

/** The roster person carried by the weekly grid / monthly report rows. */
export type GridEmployee = {
    id: number;
    full_name: string;
    initials: string;
    employee_no: string;
    photo: string | null;
    department: { id: number; name: string } | null;
};

export type WeekDayHeader = {
    date: string;
    weekday: string;
    day: string;
    is_today: boolean;
    is_future: boolean;
};

export type WeekCell = {
    date: string;
    status: AttendanceStatus | null;
    worked_minutes: number;
    late_minutes: number;
    overtime_minutes: number;
    undertime_minutes: number;
    first_in_at: string | null;
    last_out_at: string | null;
    hashid: string | null;
    is_future: boolean;
};

export type WeeklyRow = {
    employee: GridEmployee;
    cells: WeekCell[];
};

export type WeeklyView = {
    start: string;
    end: string;
    days: WeekDayHeader[];
    rows: WeeklyRow[];
};

// ── Monthly report ───────────────────────────────────────────────────────────

export type MonthlyRow = {
    employee: GridEmployee;
    present_days: number;
    late_count: number;
    absent_count: number;
    overtime_hours: number;
    worked_hours: number;
    attendance_rate: number | null;
    trend: number[];
};

export type MonthlyReport = {
    start: string;
    end: string;
    label: string;
    rows: MonthlyRow[];
};

export type AttendanceIndexPageProps = {
    records: AttendanceRecord[];
    week: WeeklyView | null;
    report: MonthlyReport | null;
    stats: AttendanceStats;
    options: {
        departments: DepartmentRef[];
        employees: EmployeeOption[];
    };
    can: AttendancePermissions;
    filters: AttendanceFilters;
};

// ── Self-service ───────────────────────────────────────────────────────────────

export type MySchedule = {
    name: string;
    start_time: string | null;
    end_time: string | null;
};

export type MySummary = {
    worked_hours: number;
    overtime_hours: number;
    late_count: number;
    absent_count: number;
};

export type MyAttendancePageProps = {
    employee: {
        full_name: string;
        initials: string;
        photo: string | null;
        employee_no: string;
        schedule: MySchedule | null;
    };
    today: AttendanceRecord;
    nextExpected: PunchType | null;
    allowed: PunchType[];
    history: AttendanceRecord[];
    summary: MySummary;
    can: { clock: boolean };
};
