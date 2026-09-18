<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOrganizations;
use App\Models\AttendancePeriod;
use App\Models\AttendanceRecord;
use App\Support\Attendance\AttendanceClock;
use App\Support\HolidayCalendar;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Re-judge stored attendance days — the backfill after ADR 0036, and the repair
 * tool later phases reuse.
 *
 * For every record in range it gives a day written before snapshots existed the
 * snapshot it should have had — the instants its copied shift times fall on in the
 * organisation's zone, and the rules of the schedule the record names (never the
 * employee's schedule today) — then recomputes the day from its punches, and
 * reports each day whose status or totals moved. `--dry-run` reports the same
 * table and writes nothing.
 *
 * It never re-applies a *changed* schedule or policy to a day that already has a
 * snapshot; that stays HR's explicit "re-apply". It is also how the minute
 * buckets ADR 0038 added are filled for days recorded before them: a day with no
 * policy in its snapshot is judged by the built-in fallback, which reaches the
 * status and totals it already had. A day inside a locked attendance period is
 * never touched (ADR 0039) — it is counted and left as payroll received it.
 * Organisations are walked
 * one at a time with each bound as the tenant, so every day is judged on its own
 * organisation's clock.
 */
class RecomputeAttendance extends Command
{
    use ResolvesOrganizations;

    protected $signature = 'attendance:recompute
        {--organization= : Only this organisation (id or slug)}
        {--from= : First work date to recompute (Y-m-d)}
        {--to= : Last work date to recompute (Y-m-d)}
        {--dry-run : Report what would change without writing anything}';

    protected $description = 'Fill missing attendance snapshots and recompute stored days, reporting what changed';

    /** Rows printed before the report is summarised. */
    private const REPORT_LIMIT = 500;

    public function handle(Tenancy $tenancy, AttendanceClock $clock): int
    {
        $organizations = $this->organizations();
        $from = $this->dateOption('from');
        $to = $this->dateOption('to');

        if ($organizations === null || $from === false || $to === false) {
            return self::FAILURE;
        }

        if ($from !== null && $to !== null && $from > $to) {
            $this->error('--from must not be after --to.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $report = [];
        $checked = 0;
        $filled = 0;
        $locked = 0;

        foreach ($organizations as $organization) {
            $tenancy->runFor($organization, function () use ($organization, $clock, $from, $to, $dryRun, &$report, &$checked, &$filled, &$locked): void {
                $query = AttendanceRecord::query()
                    ->when($from, fn (Builder $q) => $q->whereDate('work_date', '>=', $from))
                    ->when($to, fn (Builder $q) => $q->whereDate('work_date', '<=', $to));

                $first = (clone $query)->min('work_date');
                $last = (clone $query)->max('work_date');

                if ($first === null || $last === null) {
                    return;
                }

                // The span's holidays once per organisation, not once per day.
                $holidays = HolidayCalendar::inRange(CarbonImmutable::parse($first), CarbonImmutable::parse($last));

                // Frozen days are left exactly as they are (ADR 0039).
                $periods = $clock->lockedPeriodsBetween(CarbonImmutable::parse($first)->toDateString(), CarbonImmutable::parse($last)->toDateString());

                // In date order: weekly overtime and a monthly grace allowance read
                // the days before each one as they have just been saved (ADR 0038).
                $query->with('employee:id,first_name,middle_name,last_name,suffix')
                    ->orderBy('work_date')
                    ->orderBy('id')
                    ->chunk(200, function (Collection $records) use ($organization, $clock, $holidays, $periods, $dryRun, &$report, &$checked, &$filled, &$locked): void {
                        foreach ($records as $record) {
                            /** @var AttendanceRecord $record */
                            if ($periods->contains(fn (AttendancePeriod $period): bool => $period->covers($record->work_date->toDateString()))) {
                                $locked++;

                                continue;
                            }

                            $checked++;
                            $before = $this->figures($record);

                            if ($clock->fillSnapshot($record, $holidays)) {
                                $filled++;
                            }

                            $clock->evaluate($record);
                            $changes = $this->changes($before, $this->figures($record));

                            if ($changes !== []) {
                                $report[] = [
                                    $organization->name,
                                    $record->employee?->full_name ?? '—',
                                    $record->work_date->toDateString(),
                                    implode('; ', $changes),
                                ];
                            }

                            if (! $dryRun && $record->isDirty()) {
                                $record->save();
                            }
                        }
                    });
            });
        }

        if ($report !== []) {
            $this->table(['Organisation', 'Employee', 'Work date', 'Change'], array_slice($report, 0, self::REPORT_LIMIT));

            if (count($report) > self::REPORT_LIMIT) {
                $this->line('…and '.(count($report) - self::REPORT_LIMIT).' more.');
            }
        }

        $this->info(sprintf(
            '%d %s checked, %d %s given a snapshot, %d %s changed.',
            $checked, str('day')->plural($checked),
            $filled, str('day')->plural($filled),
            count($report), str('day')->plural(count($report)),
        ));

        if ($locked > 0) {
            $this->comment(sprintf('%d %s in locked periods left as they are.', $locked, str('day')->plural($locked)));
        }

        if ($dryRun) {
            $this->comment('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * The figures a recompute can move, as the report prints them.
     *
     * @return array<string, int|string|null>
     */
    private function figures(AttendanceRecord $record): array
    {
        return [
            'status' => $record->status,
            'in' => $record->first_in_at?->toIso8601String(),
            'out' => $record->last_out_at?->toIso8601String(),
            'worked' => (int) $record->worked_minutes,
            'break' => (int) $record->break_minutes,
            'late' => (int) $record->late_minutes,
            'undertime' => (int) $record->undertime_minutes,
            'overtime' => (int) $record->overtime_minutes,
            'regular' => (int) $record->regular_minutes,
            'approved overtime' => (int) $record->approved_overtime_minutes,
            'night' => (int) $record->night_minutes,
            'rest day' => (int) $record->rest_day_minutes,
            'holiday' => (int) $record->holiday_minutes,
            'sign-off' => $record->approval_status,
        ];
    }

    /**
     * "late: 0 → 30" for each figure that moved.
     *
     * @param  array<string, int|string|null>  $before
     * @param  array<string, int|string|null>  $after
     * @return list<string>
     */
    private function changes(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[] = "{$field}: ".($before[$field] ?? '—').' → '.($value ?? '—');
            }
        }

        return $changes;
    }

    /**
     * A Y-m-d option, null when absent, false (with the error printed) when malformed.
     */
    private function dateOption(string $name): string|false|null
    {
        $value = trim((string) $this->option($name));

        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->error("--{$name} must be a date written as YYYY-MM-DD.");

            return false;
        }

        return $value;
    }
}
