<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Organization;
use App\Support\Attendance\AttendanceCalculator;
use App\Support\Attendance\AttendanceClock;
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
 * Punches are written through the canonical {@see AttendanceClock}/{@see
 * AttendanceCalculator}, so seeded totals and statuses are computed exactly as a
 * live punch would be. Absent days are simply left empty (the roster synthesises
 * them), matching production.
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
        $today = CarbonImmutable::today();
        $start = $today->subDays(self::DAYS);

        Employee::with('workSchedule')->each(function (Employee $employee) use ($clock, $start, $today): void {
            $schedule = $employee->workSchedule;
            $profile = $this->profile();

            for ($day = $start; $day->lte($today); $day = $day->addDay()) {
                if (! AttendanceCalculator::isWorkingDay($day, $schedule)) {
                    continue;
                }

                $scenario = $this->scenario($profile, $day->isSameDay($today));

                if ($scenario === 'absent') {
                    continue;
                }

                $this->seedDay($clock, $employee, $schedule?->start_time, $schedule?->end_time, (int) ($schedule?->grace_minutes ?? 0), $day, $scenario);
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
        ?string $startTime,
        ?string $endTime,
        int $grace,
        CarbonImmutable $day,
        string $scenario,
    ): void {
        $startAt = $day->setTimeFromTimeString($startTime ?: '09:00:00');
        $endAt = $day->setTimeFromTimeString($endTime ?: '18:00:00');

        $clockIn = $scenario === 'late'
            ? $startAt->addMinutes($grace + random_int(8, 55))
            : $startAt->subMinutes(random_int(1, 14));

        $clockOut = match ($scenario) {
            'undertime' => $endAt->subMinutes(random_int(35, 95)),
            'overtime' => $endAt->addMinutes(random_int(45, 150)),
            // A clean day leaves on time (a few minutes over) so it stays "present".
            default => $endAt->addMinutes(random_int(1, 14)),
        };

        $record = $clock->openRecord($employee, $day->toDateString());
        $source = random_int(1, 100) <= 35 ? 'mobile' : 'web';

        $this->punch($record, $employee, 'clock_in', $clockIn, $source);

        if ($scenario !== 'incomplete') {
            // A lunch break around mid-shift.
            $breakStart = $day->setTimeFromTimeString('12:00:00')->addMinutes(random_int(-20, 25));
            $this->punch($record, $employee, 'break_start', $breakStart, $source);
            $this->punch($record, $employee, 'break_end', $breakStart->addMinutes(random_int(40, 75)), $source);
            $this->punch($record, $employee, 'clock_out', $clockOut, $source);
        }

        $clock->refresh($record, $employee);
    }

    private function punch(AttendanceRecord $record, Employee $employee, string $type, CarbonImmutable $at, string $source): void
    {
        $record->punches()->create([
            'employee_id' => $employee->id,
            'type' => $type,
            'punched_at' => $at,
            'source' => $source,
        ]);
    }
}
