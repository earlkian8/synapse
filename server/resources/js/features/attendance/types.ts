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
    // What approved requests say about the day (ADR 0039).
    | 'official_business'
    | 'remote_work';

export type PunchSource =
    | 'web'
    | 'mobile'
    | 'kiosk'
    | 'biometric'
    | 'manual'
    /** Written by an approved correction request (ADR 0039). */
    | 'correction';

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
    /** Needs sign-off (ADR 0039): pending while a flag awaits a manager. */
    approval_status: 'pending' | 'approved' | null;
    approved_at: string | null;
    approver?: string | null;
    /** In a locked period: nothing about the day can change. */
    is_locked?: boolean;
    locked_period?: string | null;
    employee: AttendanceEmployee | null;
    punches?: Punch[];
    /** The requests that concern the day — the day-detail fetch only. */
    requests?: DayRequest[];
    /** Punches an edit or a correction replaced — the day-detail fetch only. */
    replaced_punches?: ReplacedPunch[];
};

export type ReplacedPunch = {
    id: number;
    type: PunchType;
    punched_at: string | null;
    source: PunchSource;
    replaced_at: string | null;
    /** Replaced by an approved correction, rather than an HR edit. */
    by_request: boolean;
};

// ── Requests (ADR 0039) ──────────────────────────────────────────────────────

export type AttendanceRequestType =
    'correction' | 'overtime' | 'official_business' | 'remote_work';

export type AttendanceRequestStatus =
    'pending' | 'approved' | 'rejected' | 'cancelled';

/** The ask itself; which keys are set depends on the type. */
export type AttendanceRequestPayload = {
    time_in?: string | null;
    break_start?: string | null;
    break_end?: string | null;
    time_out?: string | null;
    minutes?: number;
    pre_approval?: boolean;
    start_time?: string | null;
    end_time?: string | null;
    location?: string | null;
};

/** A request as the day modal lists it. */
export type DayRequest = {
    hashid: string;
    type: AttendanceRequestType;
    status: AttendanceRequestStatus;
    start_date: string;
    end_date: string;
    payload: AttendanceRequestPayload;
    reason: string;
    review_note: string | null;
};

export type RequestPunch = {
    type: PunchType;
    punched_at: string | null;
    source: PunchSource;
};

export type AttendanceRequestItem = {
    id: number;
    hashid: string;
    type: AttendanceRequestType;
    status: AttendanceRequestStatus;
    start_date: string;
    end_date: string;
    payload: AttendanceRequestPayload;
    reason: string;
    attachment: string | null;
    review_note: string | null;
    reviewed_at: string | null;
    created_at: string | null;
    created_human: string | null;
    /** Set when any of its days is in a locked period. */
    locked_period: string | null;
    employee?: AttendanceEmployee | null;
    reviewer?: string | null;
    requester?: string | null;
    /** The day as it stands — the review fetch only. */
    day?: {
        hashid: string;
        status: AttendanceStatus;
        first_in_at: string | null;
        last_out_at: string | null;
        worked_minutes: number;
        overtime_minutes: number;
        approved_overtime_minutes: number;
        punches: RequestPunch[];
    } | null;
    /** What an approved correction took away — the review fetch only. */
    replaced?: RequestPunch[];
    can: { review: boolean; cancel: boolean };
};

// ── Periods (ADR 0039) ───────────────────────────────────────────────────────

export type PeriodFrequency =
    'weekly' | 'bi_weekly' | 'semi_monthly' | 'monthly';

export type PeriodChecklist = {
    pending_requests: number;
    incomplete_days: number;
    pending_sign_offs: number;
    clear: boolean;
};

export type AttendancePeriod = {
    id: number;
    hashid: string;
    label: string;
    start_date: string;
    end_date: string;
    status: 'open' | 'locked';
    phase: 'past' | 'current' | 'upcoming';
    locked_at: string | null;
    locked_by?: string | null;
    lock_note: string | null;
    unlocked_at: string | null;
    unlocked_by?: string | null;
    unlock_reason: string | null;
    has_export: boolean;
    checklist?: PeriodChecklist;
};

export type PeriodsView = {
    items: AttendancePeriod[];
    settings: { frequency: PeriodFrequency; reminder_days: number };
};

export type AttendanceStats = {
    present: number;
    late: number;
    absent: number;
    on_leave: number;
    avg_hours: number;
    /** Days awaiting sign-off. */
    pending: number;
    /** Requests awaiting a decision (ADR 0039). */
    pending_requests: number;
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
    /** The roster tab — who is due to work what (ADR 0037). */
    viewRoster: boolean;
    manageRoster: boolean;
    /** Requests and periods (ADR 0039). */
    request: boolean;
    reviewRequests: boolean;
    managePeriods: boolean;
    unlockPeriods: boolean;
};

export type AttendanceTab =
    'today' | 'weekly' | 'monthly' | 'roster' | 'requests' | 'periods';

export type AttendanceFilters = {
    date: string;
    tab: AttendanceTab;
    search: string;
    status: string;
    department: number | null;
    /** The request inbox's own filters. */
    request_status: AttendanceRequestStatus | 'all';
    request_type: AttendanceRequestType | null;
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
    roster: RosterView | null;
    requests: AttendanceRequestItem[] | null;
    periods: PeriodsView | null;
    stats: AttendanceStats;
    options: {
        departments: DepartmentRef[];
        employees: EmployeeOption[];
        schedules: ScheduleRef[];
        /** What an assignment can single somebody out to be judged by. */
        policies: PolicyRef[];
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
    requests: AttendanceRequestItem[];
    summary: MySummary;
    can: { clock: boolean; request: boolean };
};
