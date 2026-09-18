<?php

namespace App\Support\Attendance;

use App\Models\Holiday;
use Carbon\CarbonImmutable;

/**
 * The rules one attendance day is judged by, frozen onto its record when the day
 * opens (`attendance_records.rules`, ADR 0036).
 *
 * Before the snapshot a recompute read the employee's *current* schedule, so
 * editing a schedule's grace — or moving somebody to another shift — quietly
 * re-judged every past day the next time anyone corrected it. Frozen, a day's
 * verdict is a fact about that day. It changes only when HR deliberately
 * re-applies the current schedule ({@see AttendanceClock::reapplySchedule()}).
 *
 * The holiday is part of the snapshot for the same reason, and so the calculator
 * never has to look one up: it stays a pure function of the punches and these
 * rules.
 *
 * **Version 2** (ADR 0037) adds what a resolved shift knows beyond one pair of
 * times: the schedule's `type`, the day's `segments` (two or more when the shift
 * is split), the flexible core window as instants, and which link in the
 * precedence chain the shift came from. {@see fromArray()} gives any key an older
 * snapshot lacks its default, so a version 1 row still reads as a fixed shift.
 *
 * **Version 3** (ADR 0038) adds the attendance policy the day is judged by — its
 * id, name, where it came from and its complete settings — so editing a policy,
 * like editing a schedule, never re-judges a day already recorded. A version 1 or
 * 2 snapshot has none and reads as the built-in fallback, which judges exactly as
 * attendance did before policies existed.
 */
final readonly class DayRules
{
    public const VERSION = 3;

    /** What a day requires when no schedule says otherwise — the historical eight hours. */
    public const DEFAULT_REQUIRED_MINUTES = 480;

    /**
     * @param  list<array{start: string, end: string}>  $segments  Clock-face "HH:MM" pairs, for display.
     */
    public function __construct(
        public int $graceMinutes,
        public int $requiredMinutes,
        public bool $isWorkingDay,
        public ?int $workScheduleId = null,
        public ?string $scheduleName = null,
        public ?string $holidayType = null,
        public ?string $holidayName = null,
        public string $type = 'fixed',
        public array $segments = [],
        public ?CarbonImmutable $coreStartAt = null,
        public ?CarbonImmutable $coreEndAt = null,
        public int $unpaidBreakMinutes = 0,
        public string $source = 'fallback',
        public AttendancePolicySettings $policy = new AttendancePolicySettings,
        public ?int $policyId = null,
        public ?string $policyName = null,
        public string $policySource = 'fallback',
    ) {}

    /**
     * The rules a resolved shift sets for its date, with the holiday that falls
     * on it and the policy it is judged by.
     */
    public static function fromShift(ResolvedShift $shift, ?Holiday $holiday = null, ?ResolvedPolicy $policy = null): self
    {
        $policy ??= ResolvedPolicy::fallback();

        [$coreStart, $coreEnd] = $shift->coreWindow();

        return new self(
            graceMinutes: $shift->graceMinutes,
            requiredMinutes: $shift->requiredMinutes,
            isWorkingDay: $shift->isWorkingDay,
            workScheduleId: $shift->scheduleId,
            scheduleName: $shift->scheduleName,
            holidayType: $holiday?->type,
            holidayName: $holiday?->name,
            type: $shift->type,
            segments: $shift->segments,
            coreStartAt: $coreStart,
            coreEndAt: $coreEnd,
            unpaidBreakMinutes: $shift->unpaidBreakMinutes,
            source: $shift->source,
            policy: $policy->settings,
            policyId: $policy->id,
            policyName: $policy->name,
            policySource: $policy->source,
        );
    }

    /**
     * Read a stored snapshot back.
     *
     * @param  array<string, mixed>  $rules
     */
    public static function fromArray(array $rules): self
    {
        return new self(
            graceMinutes: (int) ($rules['grace_minutes'] ?? 0),
            requiredMinutes: (int) ($rules['required_minutes'] ?? self::DEFAULT_REQUIRED_MINUTES),
            isWorkingDay: (bool) ($rules['is_working_day'] ?? true),
            workScheduleId: isset($rules['work_schedule_id']) ? (int) $rules['work_schedule_id'] : null,
            scheduleName: isset($rules['schedule_name']) ? (string) $rules['schedule_name'] : null,
            holidayType: isset($rules['holiday_type']) ? (string) $rules['holiday_type'] : null,
            holidayName: isset($rules['holiday_name']) ? (string) $rules['holiday_name'] : null,
            type: isset($rules['type']) ? (string) $rules['type'] : 'fixed',
            segments: is_array($rules['segments'] ?? null) ? array_values($rules['segments']) : [],
            coreStartAt: self::instant($rules['core_start_at'] ?? null),
            coreEndAt: self::instant($rules['core_end_at'] ?? null),
            unpaidBreakMinutes: (int) ($rules['unpaid_break_minutes'] ?? 0),
            source: isset($rules['source']) ? (string) $rules['source'] : 'fallback',
            policy: AttendancePolicySettings::fromArray(is_array($rules['policy']['settings'] ?? null) ? $rules['policy']['settings'] : null),
            policyId: isset($rules['policy']['id']) ? (int) $rules['policy']['id'] : null,
            policyName: isset($rules['policy']['name']) ? (string) $rules['policy']['name'] : null,
            policySource: isset($rules['policy']['source']) ? (string) $rules['policy']['source'] : 'fallback',
        );
    }

    /**
     * The snapshot as it is stored.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'grace_minutes' => $this->graceMinutes,
            'required_minutes' => $this->requiredMinutes,
            'is_working_day' => $this->isWorkingDay,
            'work_schedule_id' => $this->workScheduleId,
            'schedule_name' => $this->scheduleName,
            'holiday_type' => $this->holidayType,
            'holiday_name' => $this->holidayName,
            'type' => $this->type,
            'segments' => $this->segments,
            'core_start_at' => $this->coreStartAt?->toIso8601String(),
            'core_end_at' => $this->coreEndAt?->toIso8601String(),
            'unpaid_break_minutes' => $this->unpaidBreakMinutes,
            'source' => $this->source,
            'policy' => [
                'id' => $this->policyId,
                'name' => $this->policyName,
                'source' => $this->policySource,
                'settings_version' => AttendancePolicySettings::VERSION,
                'settings' => $this->policy->toArray(),
            ],
        ];
    }

    /**
     * The grace lateness is forgiven by: the policy's, when it sets one, and the
     * schedule's otherwise — which is what every day judged before policies
     * existed used.
     */
    public function graceMinutes(): int
    {
        return $this->policy->graceMinutes ?? $this->graceMinutes;
    }

    /**
     * After how many worked minutes a day's overtime begins: the policy's daily
     * threshold, or the day's own required minutes.
     */
    public function dailyOvertimeAfter(): int
    {
        return $this->policy->overtimeDailyAfterMinutes ?? $this->requiredMinutes;
    }

    /**
     * Whether the day is a holiday nobody is expected to work — `regular` or
     * `special_non_working`. A `special_working` holiday is an ordinary working
     * day, as it is for Leave.
     */
    public function isNonWorkingHoliday(): bool
    {
        return $this->holidayType !== null && in_array($this->holidayType, Holiday::NON_WORKING_TYPES, true);
    }

    /**
     * Whether the day's shift is more than one stretch of hours — a split shift,
     * where the gap between the halves is neither work nor break.
     */
    public function isSplitShift(): bool
    {
        return count($this->segments) > 1;
    }

    /**
     * A stored ISO instant, or null when the snapshot has none.
     */
    private static function instant(mixed $value): ?CarbonImmutable
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->utc() : null;
    }
}
