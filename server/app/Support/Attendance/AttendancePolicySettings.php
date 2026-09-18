<?php

namespace App\Support\Attendance;

use App\Models\AttendancePolicy;
use App\Models\AttendancePunch;

/**
 * How a company judges a day (ADR 0038): the typed options an
 * {@see AttendancePolicy} stores as JSON, read back with a default for every key
 * so a policy saved by an older release — or a day snapshotted before policies
 * existed — still evaluates.
 *
 * **The defaults are the built-in fallback**, and the fallback reproduces what
 * attendance did before policies existed exactly: grace and required hours from
 * the shift, overtime as whatever was worked beyond them, no rounding, no
 * thresholds, breaks exactly as punched. {@see fallback()} is simply a policy
 * with nothing set, so "an unconfigured tenant's numbers do not move" is true
 * by construction rather than by a second code path.
 *
 * Two values are nullable because the shift already says them, and a policy
 * that leaves them unset defers to it: `grace_minutes` (the schedule's grace)
 * and `overtime.daily_after_minutes` (the day's required minutes).
 *
 * Presets, not a rule language: there are no formulas here, only choices
 * ({@see AttendancePolicyPresets}).
 */
final readonly class AttendancePolicySettings
{
    /** The shape of the stored JSON. Bumped when a key changes meaning. */
    public const VERSION = 1;

    public const GRACE_MODES = ['per_day', 'monthly_allowance'];

    public const UNDERTIME_BASES = ['schedule', 'hours'];

    public const ROUNDING_MODES = ['none', 'nearest', 'up', 'down'];

    public const ROUNDING_UNITS = [5, 10, 15, 30];

    public const ROUNDING_TARGETS = ['in', 'out', 'both'];

    public const OVERTIME_BASES = ['none', 'daily', 'weekly', 'daily_and_weekly'];

    public const MISSING_CLOCK_OUT_ACTIONS = ['flag', 'auto_close_at_shift_end', 'auto_close_after_minutes'];

    public const GEOFENCE_MODES = ['off', 'flag', 'block'];

    /** What a shift is spread over before a forgotten clock-out stops claiming punches — ADR 0036's sixteen hours. */
    public const DEFAULT_MAX_SHIFT_SPAN_MINUTES = 960;

    /** How early a clock-in still counts towards the shift — ADR 0036's four hours. */
    public const DEFAULT_EARLY_CLOCK_IN_MINUTES = 240;

    /**
     * @param  list<string>  $allowedSources
     * @param  list<string>  $webIpAllowlist
     */
    public function __construct(
        // Punch windows
        public int $earlyClockInMinutes = self::DEFAULT_EARLY_CLOCK_IN_MINUTES,
        public int $maxShiftSpanMinutes = self::DEFAULT_MAX_SHIFT_SPAN_MINUTES,

        // Grace & lateness
        public bool $lateEnabled = true,
        public ?int $graceMinutes = null,
        public string $graceMode = 'per_day',
        public int $monthlyGraceMinutes = 0,
        public ?int $lateHalfDayAfterMinutes = null,
        public ?int $lateAbsentAfterMinutes = null,

        // Undertime
        public string $undertimeBasis = 'schedule',
        public ?int $undertimeHalfDayBelowMinutes = null,
        public ?int $minimumMinutesForPresent = null,

        // Rounding
        public string $roundingMode = 'none',
        public int $roundingUnit = 15,
        public string $roundingApplyTo = 'both',

        // Breaks
        public int $paidBreakMinutes = 0,
        public int $autoDeductBreakMinutes = 0,
        public int $autoDeductAfterWorkedMinutes = 0,
        public ?int $maxBreakMinutes = null,

        // Overtime
        public string $overtimeBasis = 'daily',
        public ?int $overtimeDailyAfterMinutes = null,
        public int $overtimeWeeklyAfterMinutes = 2400,
        public int $overtimeMinBlockMinutes = 0,
        public bool $overtimeCountEarlyClockIn = true,
        public bool $overtimeRequiresApproval = false,
        public bool $overtimeRestDayAllOvertime = false,
        public bool $overtimeHolidayAllOvertime = false,

        // Missing punches — declared here, applied by the end-of-day job (Phase 4)
        public string $missingClockOutAction = 'flag',
        public int $missingClockOutAfterMinutes = 120,

        // Night differential
        public bool $nightEnabled = false,
        public string $nightStart = '22:00',
        public string $nightEnd = '06:00',

        // Capture — declared here, enforced in Phase 4
        public array $allowedSources = AttendancePunch::CAPTURE_SOURCES,
        public bool $selfieRequired = false,
        public string $geofence = 'off',
        public array $webIpAllowlist = [],
    ) {}

    /**
     * The built-in fallback: a policy with nothing set.
     */
    public static function fallback(): self
    {
        return new self;
    }

    /**
     * Read stored settings back, giving every missing or malformed key its
     * default — so a partial array (a preset's overrides, an older snapshot) is a
     * complete policy.
     *
     * @param  array<string, mixed>|null  $settings
     */
    public static function fromArray(?array $settings): self
    {
        $s = $settings ?? [];
        $d = self::fallback();

        return new self(
            earlyClockInMinutes: self::int($s, 'punch_windows.early_clock_in_minutes', $d->earlyClockInMinutes, 0, 720),
            maxShiftSpanMinutes: self::int($s, 'punch_windows.max_shift_span_minutes', $d->maxShiftSpanMinutes, 240, 1440),

            lateEnabled: self::bool($s, 'lateness.enabled', $d->lateEnabled),
            graceMinutes: self::nullableInt($s, 'lateness.grace_minutes', 0, 240),
            graceMode: self::oneOf($s, 'lateness.grace_mode', self::GRACE_MODES, $d->graceMode),
            monthlyGraceMinutes: self::int($s, 'lateness.monthly_grace_minutes', $d->monthlyGraceMinutes, 0, 1440),
            lateHalfDayAfterMinutes: self::nullableInt($s, 'lateness.half_day_after_minutes', 1, 1440),
            lateAbsentAfterMinutes: self::nullableInt($s, 'lateness.absent_after_minutes', 1, 1440),

            undertimeBasis: self::oneOf($s, 'undertime.basis', self::UNDERTIME_BASES, $d->undertimeBasis),
            undertimeHalfDayBelowMinutes: self::nullableInt($s, 'undertime.half_day_below_minutes', 1, 1440),
            minimumMinutesForPresent: self::nullableInt($s, 'undertime.minimum_minutes_for_present', 1, 1440),

            roundingMode: self::oneOf($s, 'rounding.mode', self::ROUNDING_MODES, $d->roundingMode),
            roundingUnit: in_array((int) data_get($s, 'rounding.unit'), self::ROUNDING_UNITS, true) ? (int) data_get($s, 'rounding.unit') : $d->roundingUnit,
            roundingApplyTo: self::oneOf($s, 'rounding.apply_to', self::ROUNDING_TARGETS, $d->roundingApplyTo),

            paidBreakMinutes: self::int($s, 'breaks.paid_break_minutes', $d->paidBreakMinutes, 0, 480),
            autoDeductBreakMinutes: self::int($s, 'breaks.auto_deduct_minutes', $d->autoDeductBreakMinutes, 0, 480),
            autoDeductAfterWorkedMinutes: self::int($s, 'breaks.auto_deduct_after_worked_minutes', $d->autoDeductAfterWorkedMinutes, 0, 1440),
            maxBreakMinutes: self::nullableInt($s, 'breaks.max_break_minutes', 1, 480),

            overtimeBasis: self::oneOf($s, 'overtime.basis', self::OVERTIME_BASES, $d->overtimeBasis),
            overtimeDailyAfterMinutes: self::nullableInt($s, 'overtime.daily_after_minutes', 0, 1440),
            overtimeWeeklyAfterMinutes: self::int($s, 'overtime.weekly_after_minutes', $d->overtimeWeeklyAfterMinutes, 0, 10080),
            overtimeMinBlockMinutes: self::int($s, 'overtime.min_block_minutes', $d->overtimeMinBlockMinutes, 0, 480),
            overtimeCountEarlyClockIn: self::bool($s, 'overtime.count_early_clock_in', $d->overtimeCountEarlyClockIn),
            overtimeRequiresApproval: self::bool($s, 'overtime.requires_approval', $d->overtimeRequiresApproval),
            overtimeRestDayAllOvertime: self::bool($s, 'overtime.rest_day_all_overtime', $d->overtimeRestDayAllOvertime),
            overtimeHolidayAllOvertime: self::bool($s, 'overtime.holiday_all_overtime', $d->overtimeHolidayAllOvertime),

            missingClockOutAction: self::oneOf($s, 'missing_clock_out.action', self::MISSING_CLOCK_OUT_ACTIONS, $d->missingClockOutAction),
            missingClockOutAfterMinutes: self::int($s, 'missing_clock_out.after_minutes', $d->missingClockOutAfterMinutes, 0, 1440),

            nightEnabled: self::bool($s, 'night.enabled', $d->nightEnabled),
            nightStart: self::clock($s, 'night.start', $d->nightStart),
            nightEnd: self::clock($s, 'night.end', $d->nightEnd),

            allowedSources: self::sources($s, $d->allowedSources),
            selfieRequired: self::bool($s, 'capture.selfie_required', $d->selfieRequired),
            geofence: self::oneOf($s, 'capture.geofence', self::GEOFENCE_MODES, $d->geofence),
            webIpAllowlist: array_values(array_filter(
                array_map(fn ($ip): string => trim((string) $ip), (array) data_get($s, 'capture.web_ip_allowlist', [])),
                fn (string $ip): bool => $ip !== '',
            )),
        );
    }

    /**
     * The settings as they are stored — grouped the way the editor shows them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return [
            'punch_windows' => [
                'early_clock_in_minutes' => $this->earlyClockInMinutes,
                'max_shift_span_minutes' => $this->maxShiftSpanMinutes,
            ],
            'lateness' => [
                'enabled' => $this->lateEnabled,
                'grace_minutes' => $this->graceMinutes,
                'grace_mode' => $this->graceMode,
                'monthly_grace_minutes' => $this->monthlyGraceMinutes,
                'half_day_after_minutes' => $this->lateHalfDayAfterMinutes,
                'absent_after_minutes' => $this->lateAbsentAfterMinutes,
            ],
            'undertime' => [
                'basis' => $this->undertimeBasis,
                'half_day_below_minutes' => $this->undertimeHalfDayBelowMinutes,
                'minimum_minutes_for_present' => $this->minimumMinutesForPresent,
            ],
            'rounding' => [
                'mode' => $this->roundingMode,
                'unit' => $this->roundingUnit,
                'apply_to' => $this->roundingApplyTo,
            ],
            'breaks' => [
                'paid_break_minutes' => $this->paidBreakMinutes,
                'auto_deduct_minutes' => $this->autoDeductBreakMinutes,
                'auto_deduct_after_worked_minutes' => $this->autoDeductAfterWorkedMinutes,
                'max_break_minutes' => $this->maxBreakMinutes,
            ],
            'overtime' => [
                'basis' => $this->overtimeBasis,
                'daily_after_minutes' => $this->overtimeDailyAfterMinutes,
                'weekly_after_minutes' => $this->overtimeWeeklyAfterMinutes,
                'min_block_minutes' => $this->overtimeMinBlockMinutes,
                'count_early_clock_in' => $this->overtimeCountEarlyClockIn,
                'requires_approval' => $this->overtimeRequiresApproval,
                'rest_day_all_overtime' => $this->overtimeRestDayAllOvertime,
                'holiday_all_overtime' => $this->overtimeHolidayAllOvertime,
            ],
            'missing_clock_out' => [
                'action' => $this->missingClockOutAction,
                'after_minutes' => $this->missingClockOutAfterMinutes,
            ],
            'night' => [
                'enabled' => $this->nightEnabled,
                'start' => $this->nightStart,
                'end' => $this->nightEnd,
            ],
            'capture' => [
                'allowed_sources' => $this->allowedSources,
                'selfie_required' => $this->selfieRequired,
                'geofence' => $this->geofence,
                'web_ip_allowlist' => $this->webIpAllowlist,
            ],
        ];
    }

    /**
     * Whether a day's verdict needs the week's earlier minutes — weekly overtime.
     */
    public function needsWeekContext(): bool
    {
        return in_array($this->overtimeBasis, ['weekly', 'daily_and_weekly'], true);
    }

    /**
     * Whether a day's verdict needs the month's earlier lateness — a monthly
     * grace allowance.
     */
    public function needsMonthContext(): bool
    {
        return $this->lateEnabled && $this->graceMode === 'monthly_allowance';
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private static function int(array $s, string $key, int $default, int $min, int $max): int
    {
        $value = data_get($s, $key);

        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    /**
     * An optional threshold: null (or blank) is "not set".
     *
     * @param  array<string, mixed>  $s
     */
    private static function nullableInt(array $s, string $key, int $min, int $max): ?int
    {
        $value = data_get($s, $key);

        return is_numeric($value) ? max($min, min($max, (int) $value)) : null;
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private static function bool(array $s, string $key, bool $default): bool
    {
        $value = data_get($s, $key);

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  list<string>  $options
     */
    private static function oneOf(array $s, string $key, array $options, string $default): string
    {
        $value = data_get($s, $key);

        return is_string($value) && in_array($value, $options, true) ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private static function clock(array $s, string $key, string $default): string
    {
        $value = data_get($s, $key);

        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : $default;
    }

    /**
     * @param  array<string, mixed>  $s
     * @param  list<string>  $default
     * @return list<string>
     */
    private static function sources(array $s, array $default): array
    {
        $value = data_get($s, 'capture.allowed_sources');

        if (! is_array($value)) {
            return $default;
        }

        return array_values(array_intersect(AttendancePunch::CAPTURE_SOURCES, $value));
    }
}
