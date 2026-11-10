<?php

namespace Database\Seeders;

use App\Models\CheckIn;
use App\Models\Employee;
use App\Support\CheckIns\CheckInPeriod;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

/**
 * Demo mood check-ins (ADR 0102): a year of monthly answers, so the results screen,
 * its trend and the year of faces have something to read. Each department has its
 * own climate and drift (one steadily brighter, one sinking), each person varies
 * around it, and about one in four skip a month. This month is part-answered, and
 * the mobile demo employee still has theirs to do, so the Home prompt shows. Written
 * directly, as a past month would have been: no notification is sent. Idempotent.
 */
class CheckInSeeder extends Seeder
{
    public function run(): void
    {
        if (CheckIn::query()->exists()) {
            return;
        }

        mt_srand(1102);
        $periods = array_reverse(CheckInPeriod::recent(12));
        $current = CheckInPeriod::current();
        $employees = Employee::query()->where('employment_status', 'active')->get(['id', 'department_id', 'email']);

        // Each department: where its mood sits, and which way it is going.
        $climate = $employees->pluck('department_id')->unique()->mapWithKeys(fn (?int $id): array => [
            (string) $id => ['base' => 2.9 + mt_rand(0, 110) / 100, 'drift' => (mt_rand(-6, 6)) / 100],
        ]);

        $rows = [];
        foreach ($periods as $month => $period) {
            $isCurrent = $period->equalTo($current);

            foreach ($employees as $employee) {
                $skip = $isCurrent ? 45 : 25;
                if (mt_rand(1, 100) <= $skip || ($isCurrent && $employee->email === DatabaseSeeder::MOBILE_EMPLOYEE_EMAIL)) {
                    continue;
                }

                $team = $climate[(string) $employee->department_id];
                $mood = $this->clamp($team['base'] + $team['drift'] * $month + $this->noise(0.9), 1, 5);
                // The other answers lean with the mood, never exactly.
                $engagement = $this->clamp(($mood - 1) * 1.3 + 0.8 + $this->noise(1.0), 0, 6);
                $rows[] = [
                    'organization_id' => app(Tenancy::class)->id(),
                    'employee_id' => $employee->id,
                    'department_id' => $employee->department_id,
                    'period' => $period->toDateString(),
                    'mood' => $mood,
                    'satisfaction' => $this->clamp($mood + $this->noise(0.8), 1, 5),
                    'energy' => $this->clamp($engagement + $this->noise(0.7), 0, 6),
                    'enthusiasm' => $this->clamp($engagement + $this->noise(0.6), 0, 6),
                    'immersion' => $this->clamp($engagement + $this->noise(0.8), 0, 6),
                    'leave_thoughts' => $this->clamp(6 - $mood + $this->noise(0.9), 1, 5),
                    'note' => null,
                    'submitted_at' => $period->addDays(mt_rand(0, $isCurrent ? max(0, (int) now()->format('j') - 1) : 25))->setTime(mt_rand(8, 21), mt_rand(0, 59)),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            CheckIn::query()->insert($chunk);
        }
    }

    private function noise(float $spread): float
    {
        return (mt_rand(-100, 100) / 100) * $spread;
    }

    private function clamp(float $value, int $min, int $max): int
    {
        return (int) max($min, min($max, round($value)));
    }
}
