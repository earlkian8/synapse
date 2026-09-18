<?php

namespace App\Support\Attendance;

use App\Models\AttendanceRecord;
use Carbon\CarbonImmutable;

/**
 * What {@see AttendanceCalculator::evaluate()} concludes about one day (ADR 0038):
 * the status, the flags that say why, and the minutes split into buckets.
 *
 * **Minutes, not money.** `regular + overtime = worked` is a partition. `night`,
 * `rest_day` and `holiday` are tags laid over those same minutes — a rest-day
 * hour worked at 23:00 is one minute of work that is regular, night and rest day
 * all at once — because a payroll system multiplies premiums, it does not add
 * them. Attendance never holds a rate (ADR 0019).
 *
 * Everything more specific than a status is a flag, so the board's filters stay a
 * short list and do not grow one status per rule.
 */
final readonly class DayResult
{
    /** Every flag the evaluator can raise. Phase 4 adds capture flags. */
    public const FLAGS = [
        'late',
        'undertime',
        'half_day',
        'late_absent',
        'below_minimum',
        'break_deducted',
        'break_exceeded',
        'unapproved_overtime',
        'rest_day_worked',
        'holiday_worked',
    ];

    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public string $status,
        public array $flags = [],
        public ?CarbonImmutable $firstInAt = null,
        public ?CarbonImmutable $lastOutAt = null,
        public int $workedMinutes = 0,
        public int $breakMinutes = 0,
        public int $lateMinutes = 0,
        public int $excusedLateMinutes = 0,
        public int $undertimeMinutes = 0,
        public int $regularMinutes = 0,
        public int $overtimeMinutes = 0,
        public int $approvedOvertimeMinutes = 0,
        public int $nightMinutes = 0,
        public int $restDayMinutes = 0,
        public int $holidayMinutes = 0,
    ) {}

    /**
     * Write the verdict onto a record. Does not save — the caller does.
     *
     * `first_in_at` / `last_out_at` stay the raw punches: rounding changes how a
     * day is judged, never what was recorded.
     */
    public function applyTo(AttendanceRecord $record): void
    {
        $record->first_in_at = $this->firstInAt;
        $record->last_out_at = $this->lastOutAt;
        $record->worked_minutes = $this->workedMinutes;
        $record->break_minutes = $this->breakMinutes;
        $record->late_minutes = $this->lateMinutes;
        $record->excused_late_minutes = $this->excusedLateMinutes;
        $record->undertime_minutes = $this->undertimeMinutes;
        $record->regular_minutes = $this->regularMinutes;
        $record->overtime_minutes = $this->overtimeMinutes;
        $record->approved_overtime_minutes = $this->approvedOvertimeMinutes;
        $record->night_minutes = $this->nightMinutes;
        $record->rest_day_minutes = $this->restDayMinutes;
        $record->holiday_minutes = $this->holidayMinutes;
        $record->flags = $this->flags;
        $record->status = $this->status;
    }

    /**
     * The verdict as the worked example and the tests read it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'flags' => $this->flags,
            'worked_minutes' => $this->workedMinutes,
            'break_minutes' => $this->breakMinutes,
            'late_minutes' => $this->lateMinutes,
            'excused_late_minutes' => $this->excusedLateMinutes,
            'undertime_minutes' => $this->undertimeMinutes,
            'regular_minutes' => $this->regularMinutes,
            'overtime_minutes' => $this->overtimeMinutes,
            'approved_overtime_minutes' => $this->approvedOvertimeMinutes,
            'night_minutes' => $this->nightMinutes,
            'rest_day_minutes' => $this->restDayMinutes,
            'holiday_minutes' => $this->holidayMinutes,
        ];
    }
}
