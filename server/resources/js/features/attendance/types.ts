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

export type AttendanceHoliday = {
    name: string | null;
    type: 'regular' | 'special_non_working' | 'special_working';
};

export type AttendanceRecord = {
    id: number | null;
    hashid: string | null;
    work_date: string | null;
    status: AttendanceStatus;
    scheduled_start: string | null;
    scheduled_end: string | null;
    /** The shift as instants — a night shift's end is the next morning. */
    scheduled_start_at?: string | null;
    scheduled_end_at?: string | null;
    /** The schedule the day was judged by, as it was named then. */
    schedule_name?: string | null;
    /** The holiday the day fell on, when it was judged. */
    holiday?: AttendanceHoliday | null;
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
export type ScheduleRef = {
    id: number;
    name: string;
    type: 'fixed' | 'flexible' | 'hours_only';
    cycle_length_days: number;
};
export type EmployeeOption = {
    id: number;
    full_name: string;
    employee_no: string;
};

export type AttendancePermissions = {
    manage: boolean;
    clock: boolean;
    /** The roster tab — who is due to work what (ADR 0037). */
    viewRoster: boolean;
    manageRoster: boolean;
};

export type AttendanceTab = 'today' | 'weekly' | 'monthly' | 'roster';

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
    /** The holiday's name, when the day is one. */
    holiday: string | null;
    hashid: string | null;
    is_future: boolean;
    /** The shift the person was due to work, and why it applied. */
    shift: string;
    shift_source: ShiftSource;
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
    holiday_count: number;
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
    roster: RosterView | null;
    stats: AttendanceStats;
    options: {
        departments: DepartmentRef[];
        employees: EmployeeOption[];
        schedules: ScheduleRef[];
    };
    can: AttendancePermissions;
    filters: AttendanceFilters;
};

// ── Roster ───────────────────────────────────────────────────────────────────

/**
 * Why a shift applies to somebody on a day — the precedence chain, most specific
 * first (ADR 0037).
 */
export type ShiftSource =
    | 'roster'
    | 'assignment'
    | 'employee'
    | 'department'
    | 'organization'
    | 'fallback';

export type RosterDayHeader = {
    date: string;
    weekday: string;
    day: string;
    is_today: boolean;
    holiday: string | null;
};

export type RosterCell = {
    date: string;
    /** "08:00–17:00", "08:00–12:00 · 17:00–21:00", or "Rest day". */
    label: string;
    type: 'fixed' | 'flexible' | 'hours_only';
    is_working_day: boolean;
    segments: { start: string; end: string }[];
    required_minutes: number;
    schedule_name: string | null;
    source: ShiftSource;
    /** Set when this day carries a one-off override, so it can be cleared. */
    entry_hashid: string | null;
    reason: string | null;
};

export type RosterRow = {
    employee: GridEmployee;
    cells: RosterCell[];
};

export type RosterView = {
    start: string;
    end: string;
    days: RosterDayHeader[];
    rows: RosterRow[];
};

// ── Self-service ───────────────────────────────────────────────────────────────

export type MySchedule = {
    name: string;
    start_time: string | null;
    end_time: string | null;
    /** How the day reads: "08:00–17:00", a split shift, or "Rest day". */
    hours: string;
    source: ShiftSource;
    is_working_day: boolean;
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
        schedule: MySchedule;
    };
    today: AttendanceRecord;
    nextExpected: PunchType | null;
    allowed: PunchType[];
    history: AttendanceRecord[];
    summary: MySummary;
    can: { clock: boolean };
};
