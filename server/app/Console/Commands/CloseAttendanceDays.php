<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOrganizations;
use App\Support\Attendance\DayCloser;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * The end-of-day job (ADR 0041), scheduled hourly. For every organisation, on
 * its own clock, it closes the dates it can: writes the record of every working
 * day nobody punched, deals with forgotten clock-outs as each day's policy says,
 * and sends one digest of the date's exceptions to each person who should hear
 * about them ({@see DayCloser}).
 *
 * Hourly rather than at midnight because midnight is a different moment in
 * every organisation's zone, and a night shift is only over the morning after.
 * Idempotent: running it again changes nothing that is already closed.
 */
class CloseAttendanceDays extends Command
{
    use ResolvesOrganizations;

    protected $signature = 'attendance:close-day
        {--organization= : Only this organisation (id or slug)}';

    protected $description = 'Close finished attendance days: record absences, handle forgotten clock-outs, send the digest';

    public function handle(Tenancy $tenancy, DayCloser $closer): int
    {
        $organizations = $this->organizations();

        if ($organizations === null) {
            return self::FAILURE;
        }

        $totals = ['dates' => 0, 'materialised' => 0, 'auto_closed' => 0, 'flagged' => 0, 'digests' => 0];

        foreach ($organizations as $organization) {
            $report = $tenancy->runFor($organization, fn (): array => $closer->close($organization));

            foreach ($report as $key => $count) {
                $totals[$key] += $count;
            }
        }

        $this->info(sprintf(
            '%d %s closed: %d %s recorded, %d closed automatically, %d left open for HR, %d %s sent.',
            $totals['dates'], str('date')->plural($totals['dates']),
            $totals['materialised'], str('day')->plural($totals['materialised']),
            $totals['auto_closed'],
            $totals['flagged'],
            $totals['digests'], str('digest')->plural($totals['digests']),
        ));

        return self::SUCCESS;
    }
}
