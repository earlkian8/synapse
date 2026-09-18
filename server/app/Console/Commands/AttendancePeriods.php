<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOrganizations;
use App\Models\AttendancePeriod;
use App\Support\Attendance\PeriodCalendar;
use App\Support\Notifier;
use App\Support\OrganizationClock;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * The daily attendance-period job (ADR 0039), for every organisation on its own
 * clock:
 *
 *  1. **Generate ahead** — the period covering today and the one after it exist,
 *     on the organisation's calendar ({@see PeriodCalendar}).
 *  2. **Remind** — a period still open within `attendance_lock_reminder_days` of
 *     its end (or already past it) is announced once to everyone holding
 *     `attendance.period.manage`, then marked reminded.
 *
 * Idempotent: running it twice in a day creates nothing and reminds nobody twice.
 */
class AttendancePeriods extends Command
{
    use ResolvesOrganizations;

    protected $signature = 'attendance:periods
        {--organization= : Only this organisation (id or slug)}';

    protected $description = 'Generate attendance periods ahead and remind HR to lock the ones due';

    public function handle(Tenancy $tenancy, PeriodCalendar $calendar): int
    {
        $organizations = $this->organizations();

        if ($organizations === null) {
            return self::FAILURE;
        }

        $created = 0;
        $reminded = 0;

        foreach ($organizations as $organization) {
            $tenancy->runFor($organization, function () use ($organization, $calendar, &$created, &$reminded): void {
                $created += $calendar->ensureCurrent();

                $today = CarbonImmutable::parse(OrganizationClock::today());
                $dueBy = $today->addDays((int) $organization->attendance_lock_reminder_days)->toDateString();

                $due = AttendancePeriod::query()
                    ->where('status', 'open')
                    ->whereNull('reminded_at')
                    ->whereDate('end_date', '<=', $dueBy)
                    ->orderBy('start_date')
                    ->get();

                foreach ($due as $period) {
                    $ended = $period->end_date->toDateString() < $today->toDateString();

                    Notifier::toPermission(
                        'attendance.period.manage',
                        $ended ? 'Attendance period ready to lock' : 'Attendance period closing soon',
                        $ended
                            ? "{$period->label()} has ended. Check its open items and lock it so payroll gets final numbers."
                            : "{$period->label()} ends on {$period->end_date->format('M j')}. Clear its open items, then lock it.",
                        url: '/attendance?tab=periods',
                        category: 'attendance',
                    );

                    $period->forceFill(['reminded_at' => now()])->save();
                    $reminded++;
                }
            });
        }

        $this->info(sprintf(
            '%d %s generated, %d %s reminded.',
            $created, str('period')->plural($created),
            $reminded, str('period')->plural($reminded),
        ));

        return self::SUCCESS;
    }
}
