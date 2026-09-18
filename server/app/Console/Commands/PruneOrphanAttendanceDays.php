<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOrganizations;
use App\Models\AttendanceRecord;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Delete the empty days that refused overnight clock-outs left behind (ADR 0036).
 *
 * Before work dates were anchored to shifts, a night-shift clock-out at 06:00
 * opened a record for the new calendar date *before* being refused with "You need
 * to clock in first" — and that empty record then sat on the board as an absence.
 *
 * A record is removed only when all of it says it is one of those: no punches,
 * not entered by hand, no remarks, not a day of official business, and the same
 * employee's previous day was left open (clocked in, never out). Anything else
 * with no punches is a real day and is left alone. Run it with `--dry-run` first.
 */
class PruneOrphanAttendanceDays extends Command
{
    use ResolvesOrganizations;

    protected $signature = 'attendance:prune-orphan-days
        {--organization= : Only this organisation (id or slug)}
        {--dry-run : List the days that would be deleted without deleting them}';

    protected $description = 'Delete empty attendance days left by refused overnight clock-outs';

    public function handle(Tenancy $tenancy): int
    {
        $organizations = $this->organizations();

        if ($organizations === null) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = [];

        foreach ($organizations as $organization) {
            $tenancy->runFor($organization, function () use ($organization, $dryRun, &$rows): void {
                $candidates = AttendanceRecord::query()
                    ->doesntHave('punches')
                    ->where('is_manual', false)
                    ->whereNull('remarks')
                    ->with('employee:id,first_name,middle_name,last_name,suffix')
                    ->get();

                foreach ($candidates as $record) {
                    // A day approved official business opened has no punches by
                    // design (ADR 0039) — it is a real day, not an orphan.
                    if (in_array('official_business', $record->flags ?? [], true)) {
                        continue;
                    }

                    $previous = CarbonImmutable::parse($record->work_date->toDateString())->subDay()->toDateString();

                    $followsOpenDay = AttendanceRecord::query()
                        ->where('employee_id', $record->employee_id)
                        ->whereDate('work_date', $previous)
                        ->whereNotNull('first_in_at')
                        ->whereNull('last_out_at')
                        ->exists();

                    if (! $followsOpenDay) {
                        continue;
                    }

                    $rows[] = [$organization->name, $record->employee?->full_name ?? '—', $record->work_date->toDateString()];

                    if (! $dryRun) {
                        $record->delete();
                    }
                }
            });
        }

        if ($rows !== []) {
            $this->table(['Organisation', 'Employee', 'Work date'], $rows);
        }

        $count = count($rows);

        $this->info($dryRun
            ? "{$count} empty ".str('day')->plural($count).' would be deleted. Dry run — nothing was deleted.'
            : "{$count} empty ".str('day')->plural($count).' deleted.');

        return self::SUCCESS;
    }
}
