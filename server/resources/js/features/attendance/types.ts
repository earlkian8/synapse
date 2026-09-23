export type AttendanceStatus =
    | 'present'
    | 'late'
    | 'undertime'
    | 'half_day'
    | 'absent'
    | 'on_leave'
    | 'day_off'
    | 'holiday'
    | 'incomplete';

export type PunchType = 'clock_in' | 'clock_out' | 'break_start' | 'break_end';

/**
 * Everything more specific than a status (ADR 0038) — why a day was judged the
 * way it was.
 */
export type AttendanceFlag =
    | 'late'
    | 'undertime'
    | 'half_day'
    | 'late_absent'
    | 'below_minimum'
    | 'break_deducted'
    | 'break_exceeded'
    | 'unapproved_overtime'
    | 'rest_day_worked'
    | 'holiday_worked'
    // What capture and the end-of-day job found (ADR 0040, ADR 0041).
    | 'outside_geofence'
    | 'source_not_allowed'
    | 'device_sequence_anomaly'
    | 'clock_skew'
    | 'auto_closed'
    | 'missing_clock_out';

export type PunchSource =
    | 'web'
    | 'mobile'
    | 'kiosk'
    | 'biometric'
    | 'manual'
    /** Written by the end-of-day job closing a forgotten clock-out (ADR 0041). */
    | 'system';

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
    /** The nearest work location, when the punch was placed (ADR 0040). */
    location?: { name: string; radius_meters: number } | null;
    distance_meters?: number | null;
    /** On site, off site, or not checked (null). */
    within_geofence?: boolean | null;
    /** The kiosk or scanner that sent it. */
    device?: { name: string; type: 'kiosk' | 'biometric' } | null;
    /** A phone queued it while offline and sent it later. */
    offline?: boolean;
    received_at?: string | null;
    clock_skew_seconds?: number | null;
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
    /** The attendance policy the day was judged by; no name is the built-in rules. */
    policy?: { name: string | null; source: string } | null;
    flags?: AttendanceFlag[];
    first_in_at: string | null;
    last_out_at: string | null;
    worked_minutes: number;
    break_minutes: number;
    late_minutes: number;
    undertime_minutes: number;
    overtime_minutes: number;
    /** The buckets (ADR 0038): regular + overtime = worked; the rest are tags. */
    regular_minutes?: number;
    approved_overtime_minutes?: number;
    night_minutes?: number;
    rest_day_minutes?: number;
    holiday_minutes?: number;
    is_manual: boolean;
    remarks: string | null;
    /** Needs sign-off: pending while a flag awaits a manager. */
    approval_status: 'pending' | 'approved' | null;
    approved_at: string | null;
    approver?: string | null;
    employee: AttendanceEmployee | null;
    punches?: Punch[];
    /** Punches an edit replaced — the day-detail fetch only. */
    replaced_punches?: ReplacedPunch[];
};

export type ReplacedPunch = {
    id: number;
    type: PunchType;
    punched_at: string | null;
    source: PunchSource;
    replaced_at: string | null;
};

export type AttendanceStats = {
    present: number;
    late: number;
    absent: number;
    on_leave: number;
    avg_hours: number;
    /** Days awaiting sign-off. */
    pending: number;
};

export type DepartmentRef = { id: number; name: string };
export type PolicyRef = { id: number; name: string };
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
};

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
    flags: AttendanceFlag[];
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
    half_day_count: number;
    overtime_hours: number;
    worked_hours: number;
    attendance_rate: number | null;
    trend: number[];
    /** Every bucket for the month, in minutes (ADR 0038). */
    minutes: {
        worked: number;
        late: number;
        undertime: number;
        regular: number;
        overtime: number;
        approved_overtime: number;
        night: number;
        rest_day: number;
        holiday: number;
    };
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

// ── Shifts ───────────────────────────────────────────────────────────────────

/**
 * Why a shift applies to somebody on a day — the precedence chain, most specific
 * first (ADR 0037).
 */
export type ShiftSource =
    | 'roster'
    | 'assignment'
    | 'employee'
    | 'department'
    | 'location'
    | 'organization'
    | 'fallback';

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
