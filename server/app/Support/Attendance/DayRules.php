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
 */
final readonly class DayRules
{
    public const VERSION = 2;

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
    ) {}

    /**
     * The rules a resolved shift sets for its date, with the holiday that falls
     * on it.
     */
    public static function fromShift(ResolvedShift $shift, ?Holiday $holiday = null): self
    {
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
        ];
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
