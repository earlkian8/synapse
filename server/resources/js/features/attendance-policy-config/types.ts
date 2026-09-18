/**
 * An attendance policy's settings, grouped as the server stores them
 * (`AttendancePolicySettings::toArray()`, ADR 0038). Every group is always
 * present; a `null` threshold means "not set".
 */
export type PolicySettings = {
    punch_windows: {
        early_clock_in_minutes: number;
        max_shift_span_minutes: number;
    };
    lateness: {
        enabled: boolean;
        /** Null defers to each schedule's own grace. */
        grace_minutes: number | null;
        grace_mode: GraceMode;
        monthly_grace_minutes: number;
        half_day_after_minutes: number | null;
        absent_after_minutes: number | null;
    };
    undertime: {
        basis: UndertimeBasis;
        half_day_below_minutes: number | null;
        minimum_minutes_for_present: number | null;
    };
    rounding: {
        mode: RoundingMode;
        unit: RoundingUnit;
        apply_to: RoundingTarget;
    };
    breaks: {
        paid_break_minutes: number;
        auto_deduct_minutes: number;
        auto_deduct_after_worked_minutes: number;
        max_break_minutes: number | null;
    };
    overtime: {
        basis: OvertimeBasis;
        /** Null is "after the day's required hours". */
        daily_after_minutes: number | null;
        weekly_after_minutes: number;
        min_block_minutes: number;
        count_early_clock_in: boolean;
        requires_approval: boolean;
        rest_day_all_overtime: boolean;
        holiday_all_overtime: boolean;
    };
    missing_clock_out: {
        action: MissingClockOutAction;
        after_minutes: number;
    };
    night: {
        enabled: boolean;
        start: string; // "HH:MM"
        end: string; // "HH:MM"
    };
    capture: {
        allowed_sources: PunchSource[];
        selfie_required: boolean;
        geofence: GeofenceMode;
        web_ip_allowlist: string[];
    };
};

export type GraceMode = 'per_day' | 'monthly_allowance';
export type UndertimeBasis = 'schedule' | 'hours';
export type RoundingMode = 'none' | 'nearest' | 'up' | 'down';
export type RoundingUnit = 5 | 10 | 15 | 30;
export type RoundingTarget = 'in' | 'out' | 'both';
export type OvertimeBasis = 'none' | 'daily' | 'weekly' | 'daily_and_weekly';
export type MissingClockOutAction =
    'flag' | 'auto_close_at_shift_end' | 'auto_close_after_minutes';
export type GeofenceMode = 'off' | 'flag' | 'block';
export type PunchSource = 'web' | 'mobile' | 'kiosk' | 'biometric' | 'manual';

/** A server-defined starting point (`AttendancePolicyPresets`). */
export type PolicyPreset = {
    key: string;
    name: string;
    description: string;
    /** The rules its card leads with. */
    highlights: string[];
    /** Complete — the preset laid over the built-in rules. */
    settings: PolicySettings;
};

export type AttendancePolicy = {
    id: number;
    hashid: string;
    name: string;
    description: string | null;
    preset_key: string | null;
    preset_name: string | null;
    is_default: boolean;
    settings: PolicySettings;
    schedules_count: number;
    departments_count: number;
    assignments_count: number;
    is_archived: boolean;
};

export type PolicyOption = { id: number; name: string; is_default?: boolean };

export type AttendancePoliciesPageProps = {
    policies: AttendancePolicy[];
    archivedPolicies: AttendancePolicy[];
    presets: PolicyPreset[];
    /** What a policy that sets nothing judges by — "start from scratch". */
    fallback: PolicySettings;
    sources: PunchSource[];
    can: { manage: boolean };
};

// ── The worked example ───────────────────────────────────────────────────────

export type SampleDayKind = 'working' | 'rest_day' | 'holiday';

/** The day the worked example judges. Times are "HH:MM" on the company clock. */
export type SampleDay = {
    day: SampleDayKind;
    shift_start: string;
    shift_end: string;
    required_minutes: number;
    grace_minutes: number;
    time_in: string;
    time_out: string;
    break_start: string;
    break_end: string;
};

/** What the server's evaluator made of the sample (`DayResult::toArray()`). */
export type DayVerdict = {
    status: string;
    flags: string[];
    worked_minutes: number;
    break_minutes: number;
    late_minutes: number;
    excused_late_minutes: number;
    undertime_minutes: number;
    regular_minutes: number;
    overtime_minutes: number;
    approved_overtime_minutes: number;
    night_minutes: number;
    rest_day_minutes: number;
    holiday_minutes: number;
};
