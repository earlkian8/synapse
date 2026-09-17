<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Organization;
use App\Support\Attendance\AttendanceCalculator;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\ResolvedShift;
use App\Support\Attendance\ShiftResolver;
use App\Support\HolidayCalendar;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo Daily Time Records: ~6 weeks of punches across the team so the attendance
 * workspace (daily log, weekly grid, monthly report, heatmap and exceptions) has
 * something real to show. Each employee gets a punctuality profile, so patterns
 * — the chronically late, the occasional no-show, the overtime grinder — emerge
 * across the week and month rather than looking uniformly random.
 *
 * Punches are real instants on the organisation's clock ({@see OrganizationClock}):
 * a Day Shift clock-in at 07:52 Manila is stored as 23:52Z the evening before,
 * and a Night Shift runs 22:00 into the next morning on one record, exactly as a
 * live punch would. Totals and statuses come from the canonical {@see AttendanceClock}
 * / {@see AttendanceCalculator}. Which shift each person works on each day comes
 * from {@see ShiftResolver}, so a seeded rotation or split shift is punched the
 * way it is rostered. Absent days, rest days and non-working holidays
 * are simply left empty (the roster synthesises them), matching production, and
 * today is only seeded as far as it has happened.
 */
class AttendanceSeeder extends Seeder
{
    /** How many days back to seed. */
    private const DAYS = 42;

    public function run(): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->check()) {
            $organization = Organization::first();

            if (! $organization) {
                return;
            }

            $tenancy->set($organization);
        }

        // Idempotent: skip once the team clearly has history.
        if (AttendanceRecord::count() > 10) {
            return;
        }

        $clock = app(AttendanceClock::class);
        $now = CarbonImmutable::now();
        $today = CarbonImmutable::parse(OrganizationClock::today());
        $start = $today->subDays(self::DAYS);
        $holidays = HolidayCalendar::inRange($start, $today);

        $employees = Employee::all();
        $shifts = app(ShiftResolver::class)->forMany($employees, $start->toDateString(), $today->toDateString());

        $employees->each(function (Employee $employee) use ($clock, $shifts, $start, $today, $now, $holidays): void {
            $profile = $this->profile();

            for ($day = $start; $day->lte($today); $day = $day->addDay()) {
                $date = $day->toDateString();
                $holiday = $holidays[$date] ?? null;
                $shift = $shifts[$employee->id][$date] ?? ResolvedShift::fallback($date);

                if (! $shift->isWorkingDay
                    || ($holiday !== null && in_array($holiday->type, Holiday::NON_WORKING_TYPES, true))) {
                    continue;
                }

                $scenario = $this->scenario($profile, $day->isSameDay($today));

                if ($scenario === 'absent') {
                    continue;
                }

                $this->seedDay($clock, $employee, $shift, $scenario, $now);
            }
        });
    }

    /**
     * A per-employee punctuality profile — the relative weights of each day kind.
     *
     * @return array{late: int, undertime: int, overtime: int, incomplete: int, absent: int}
     */
    private function profile(): array
    {
        return [
            'late' => random_int(2, 30),
            'undertime' => random_int(1, 12),
            'overtime' => random_int(5, 40),
            'incomplete' => random_int(1, 6),
            'absent' => random_int(1, 8),
        ];
    }

    /**
     * Pick the kind of day for one employee, weighted by their profile. Today
     * skews toward "still clocked in" so the live board has open days to resolve.
     *
     * @param  array{late: int, undertime: int, overtime: int, incomplete: int, absent: int}  $profile
     */
    private function scenario(array $profile, bool $isToday): string
    {
        if ($isToday) {
            return random_int(1, 100) <= 55 ? 'incomplete' : 'present';
        }

        $roll = random_int(1, 100);
        $cursor = 0;

        foreach (['late', 'undertime', 'incomplete', 'absent'] as $kind) {
            $cursor += $profile[$kind];

            if ($roll <= $cursor) {
                return $kind;
            }
        }

        // Otherwise present — possibly with overtime.
        return random_int(1, 100) <= $profile['overtime'] ? 'overtime' : 'present';
    }

    /**
     * Write one day's punches for the chosen scenario and recompute the record.
     */
    private function seedDay(
        AttendanceClock $clock,
        Employee $employee,
        ResolvedShift $shift,
        string $scenario,
        CarbonImmutable $now,
    ): void {
        // The shift as instants on the organisation's clock; a night shift's end
        // is the next morning, and a split shift is punched across its whole span.
        $startAt = $shift->startsAt();
        $endAt = $shift->endsAt();

        if ($startAt === null || $endAt === null) {
            return;
        }

        $date = $shift->date;
        $grace = $shift->graceMinutes;

        $clockIn = $scenario === 'late'
            ? $startAt->addMinutes($grace + random_int(8, 55))
            : $startAt->subMinutes(random_int(1, 14));

        $clockOut = match ($scenario) {
            'undertime' => $endAt->subMinutes(random_int(35, 95)),
            'overtime' => $endAt->addMinutes(random_int(45, 150)),
            // A clean day leaves on time (a few minutes over) so it stays "present".
            default => $endAt->addMinutes(random_int(1, 14)),
        };

        // A meal break around the middle of the shift, whenever the shift is.
        $halfShift = intdiv($endAt->getTimestamp() - $startAt->getTimestamp(), 120);
        $breakStart = $startAt->addMinutes($halfShift - 30 + random_int(-20, 25));
        $breakEnd = $breakStart->addMinutes(random_int(40, 75));

        // Nothing is seeded that has not happened yet: a shift still under way
        // today stops at its last punch so far.
        if ($clockIn->gt($now)) {
            return;
        }

        $punches = [['clock_in', $clockIn]];

        if ($scenario !== 'incomplete') {
            foreach ([['break_start', $breakStart], ['break_end', $breakEnd], ['clock_out', $clockOut]] as $punch) {
                if ($punch[1]->gt($now)) {
                    break;
                }

                $punches[] = $punch;
            }
        }

        $record = $clock->openRecord($employee, $date);
        $source = random_int(1, 100) <= 35 ? 'mobile' : 'web';

        foreach ($punches as [$type, $at]) {
            $record->punches()->create([
                'employee_id' => $employee->id,
                'type' => $type,
                'punched_at' => $at,
                'source' => $source,
            ]);
        }

        $clock->refresh($record);
    }
}
