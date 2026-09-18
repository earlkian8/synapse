<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Queries\AttendanceMonthlyReport;
use App\Queries\AttendanceRecordsIndexQuery;
use App\Queries\AttendanceWeeklyQuery;
use App\Support\Attendance\PeriodSummaryExport;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the attendance board as a CSV download, matching whichever tab the HR
 * user is viewing (daily log, weekly timesheet, or monthly summary) and honouring
 * the active date / department / search / status filters. Reuses the same queries
 * that build the on-screen views so the export always mirrors what's displayed.
 *
 * `tab=period` is the **payroll period summary** (ADR 0038): one row per employee
 * for `from`–`to` (the anchor date's month when they are omitted) with days
 * worked, absences, half days, late and undertime minutes, and every minute
 * bucket — approved overtime apart from computed overtime. Its columns are
 * documented in docs/modules/attendance-policies.md so an external payroll system
 * can map them; they are minutes, never money (ADR 0019).
 */
class AttendanceExportController extends Controller
{
    /**
     * The payroll period summary's columns, in order — written by
     * {@see PeriodSummaryExport}, which a locked period's file uses too.
     */
    public const PERIOD_COLUMNS = PeriodSummaryExport::COLUMNS;

    public function __invoke(
        Request $request,
        AttendanceRecordsIndexQuery $roster,
        AttendanceWeeklyQuery $weekly,
        AttendanceMonthlyReport $monthly,
        PeriodSummaryExport $summary,
    ): StreamedResponse {
        $date = $roster->date($request);
        $tab = $this->tab($request);
        $department = $request->integer('department') ?: null;
        $search = $request->string('search')->toString();

        [$from, $to] = $tab === 'period' ? $this->period($request, $date) : [$date, $date];

        $filename = $tab === 'period' ? "attendance-period-{$from}-to-{$to}.csv" : "attendance-{$tab}-{$date}.csv";
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($tab, $date, $from, $to, $department, $search, $request, $roster, $weekly, $monthly, $summary): void {
            $handle = fopen('php://output', 'w');

            match ($tab) {
                'weekly' => $this->weekly($handle, $weekly->toArray($date, $department, $search)),
                'monthly' => $this->monthly($handle, $monthly->toArray($date, $department, $search)),
                'period' => $summary->write($handle, $from, $to, $department, $search),
                default => $this->daily($handle, $roster->get($request)),
            };

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * The active tab, defaulting to the daily log (mirrors the index controller).
     */
    private function tab(Request $request): string
    {
        $tab = $request->string('tab')->toString();

        return in_array($tab, ['today', 'weekly', 'monthly', 'period'], true) ? $tab : 'today';
    }

    /**
     * The period a summary covers: `from` / `to` when both are given (at most 62
     * days — a semi-monthly or monthly cut-off, with room for a long month's
     * overlap), otherwise the anchor date's month.
     *
     * @return array{0: string, 1: string}
     */
    private function period(Request $request, string $date): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'required_with:to'],
            'to' => ['nullable', 'date_format:Y-m-d', 'required_with:from', 'after_or_equal:from'],
        ]);

        if (isset($validated['from'], $validated['to'])) {
            $from = CarbonImmutable::parse($validated['from']);
            $to = CarbonImmutable::parse($validated['to']);

            abort_if($from->diffInDays($to) > 61, 422, 'A period summary covers at most 62 days.');

            return [$from->toDateString(), $to->toDateString()];
        }

        $month = CarbonImmutable::parse($date);

        return [$month->startOfMonth()->toDateString(), $month->endOfMonth()->toDateString()];
    }

    /**
     * The daily log: one row per employee on the date.
     *
     * @param  resource  $handle
     * @param  Collection<int, AttendanceRecord>  $records
     */
    private function daily($handle, $records): void
    {
        fputcsv($handle, [
            'Employee', 'Employee No.', 'Department', 'Status', 'Time In', 'Time Out',
            'Worked (h)', 'Late (min)', 'Undertime (min)', 'Regular (min)', 'Overtime (min)',
            'Approved Overtime (min)', 'Night (min)', 'Rest Day (min)', 'Holiday (min)',
            'Flags', 'Policy', 'Approval', 'Remarks',
        ]);

        foreach ($records as $record) {
            /** @var AttendanceRecord $record */
            fputcsv($handle, [
                $record->employee?->full_name,
                $record->employee?->employee_no,
                $record->employee?->department?->name,
                $record->status,
                $this->time($record->first_in_at),
                $this->time($record->last_out_at),
                $this->hours($record->worked_minutes),
                $record->late_minutes,
                $record->undertime_minutes,
                $record->regular_minutes,
                $record->overtime_minutes,
                $record->approved_overtime_minutes,
                $record->night_minutes,
                $record->rest_day_minutes,
                $record->holiday_minutes,
                implode(' ', $record->flags ?? []),
                $record->rules['policy']['name'] ?? '',
                $record->approval_status,
                $record->remarks,
            ]);
        }
    }

    /**
     * The weekly timesheet: one row per employee with a worked-hours column per day.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $week
     */
    private function weekly($handle, array $week): void
    {
        $dayLabels = array_map(
            fn (array $day): string => Carbon::parse($day['date'])->format('D j'),
            $week['days'],
        );

        fputcsv($handle, ['Employee', 'Employee No.', 'Department', ...$dayLabels, 'Total (h)']);

        foreach ($week['rows'] as $row) {
            $daily = array_map(
                fn (array $cell): string => $this->hours((int) $cell['worked_minutes']),
                $row['cells'],
            );
            $total = array_sum(array_map(fn (array $cell): int => (int) $cell['worked_minutes'], $row['cells']));

            fputcsv($handle, [
                $row['employee']['full_name'],
                $row['employee']['employee_no'],
                $row['employee']['department']['name'] ?? null,
                ...$daily,
                $this->hours($total),
            ]);
        }
    }

    /**
     * The monthly summary: one roll-up row per employee.
     *
     * @param  resource  $handle
     * @param  array<string, mixed>  $report
     */
    private function monthly($handle, array $report): void
    {
        fputcsv($handle, [
            'Employee', 'Employee No.', 'Department', 'Present Days', 'Late', 'Half Days', 'Absent', 'Holidays',
            'Worked (h)', 'Regular (h)', 'Overtime (h)', 'Approved Overtime (h)', 'Night (h)', 'Rest Day (h)', 'Holiday (h)',
            'Attendance %',
        ]);

        foreach ($report['rows'] as $row) {
            fputcsv($handle, [
                $row['employee']['full_name'],
                $row['employee']['employee_no'],
                $row['employee']['department']['name'] ?? null,
                $row['present_days'],
                $row['late_count'],
                $row['half_day_count'],
                $row['absent_count'],
                $row['holiday_count'],
                $row['worked_hours'],
                $this->hours($row['minutes']['regular']),
                $row['overtime_hours'],
                $this->hours($row['minutes']['approved_overtime']),
                $this->hours($row['minutes']['night']),
                $this->hours($row['minutes']['rest_day']),
                $this->hours($row['minutes']['holiday']),
                $row['attendance_rate'] === null ? '' : $row['attendance_rate'],
            ]);
        }
    }

    /**
     * A stored instant as the organisation's clock showed it — the export is read
     * by people, and payroll reads 08:30, not 00:30Z (ADR 0036).
     */
    private function time(?CarbonInterface $value): string
    {
        return $value ? OrganizationClock::local($value)->format('H:i') : '';
    }

    private function hours(int $minutes): string
    {
        return $minutes > 0 ? (string) round($minutes / 60, 1) : '';
    }
}
