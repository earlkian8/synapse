<?php

namespace App\Support\Attendance;

use Carbon\CarbonImmutable;

/**
 * Everything {@see AttendanceCalculator::evaluate()} needs beyond the punches, the
 * rules and the policy — gathered by the caller so the evaluation itself never
 * queries (ADR 0038).
 *
 *  - The shift's edges as instants (the record's `scheduled_start_at` /
 *    `scheduled_end_at`).
 *  - The organisation's zone, because rounding and the night window are read on
 *    the local clock: a quarter hour in Kathmandu does not start on UTC's.
 *  - Whether approved leave covers the day.
 *  - The week's regular minutes before this day (weekly overtime) and the
 *    lateness the month has already forgiven (a monthly grace allowance). Both
 *    are zero unless the policy needs them, and the caller only asks the
 *    database when it does.
 */
final readonly class DayContext
{
    public function __construct(
        public ?CarbonImmutable $scheduledStart = null,
        public ?CarbonImmutable $scheduledEnd = null,
        public string $timezone = 'UTC',
        public bool $onApprovedLeave = false,
        public int $weekRegularMinutesBefore = 0,
        public int $monthExcusedLateMinutesBefore = 0,
    ) {}
}
