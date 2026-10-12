<?php

namespace App\Support\Search\Sources;

use App\Models\AttendanceRecord;
use App\Models\User;
use App\Support\OrganizationClock;
use App\Support\Search\AttendanceSearchQuery;
use App\Support\Search\GlobalSearch;
use App\Support\Search\SearchResult;
use App\Support\Search\SearchSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Attendance days (ADR 0081): "maria late", "santos oct 3", "absent today".
 * Answers only when the words say attendance — a day, a month, a kind of day,
 * or "attendance" itself ({@see AttendanceSearchQuery}) — so a search for a
 * name still means the person. Newest day first; a row opens the day's drawer
 * on the attendance board for its date, with the person's number as the search.
 */
class AttendanceRecordSource implements SearchSource
{
    public function key(): string
    {
        return 'attendance';
    }

    public function label(): string
    {
        return 'Attendance';
    }

    public function permits(User $user): bool
    {
        return $user->can('attendance.view');
    }

    public function find(User $user, array $words, string $phrase): Collection
    {
        $query = AttendanceSearchQuery::parse($words, OrganizationClock::today());

        if (! $query->cued) {
            return collect();
        }

        $records = AttendanceRecord::query()
            ->select(['id', 'employee_id', 'work_date', 'status', 'first_in_at', 'last_out_at', 'late_minutes', 'overtime_minutes', 'undertime_minutes', 'rules'])
            // An archived employee's days stay behind, but the person is shown nowhere.
            ->whereHas('employee')
            ->with('employee:id,first_name,middle_name,last_name,suffix,employee_no')
            ->when($query->from !== null, fn (Builder $q) => $q->whereDate('work_date', '>=', $query->from)->whereDate('work_date', '<=', $query->to))
            ->when($query->kinds !== [], fn (Builder $q) => $q->where(function (Builder $q) use ($query): void {
                foreach ($query->kinds as $kind) {
                    match ($kind) {
                        'late' => $q->orWhere('late_minutes', '>', 0),
                        'undertime' => $q->orWhere('undertime_minutes', '>', 0),
                        'overtime' => $q->orWhere('overtime_minutes', '>', 0),
                        'present' => $q->orWhereIn('status', AttendanceRecord::PRESENT_STATUSES),
                        default => $q->orWhere('status', $kind),
                    };
                }
            }));

        GlobalSearch::matchEveryWord($records, $query->words);

        return $records
            ->orderByDesc('work_date')
            ->orderByDesc('id')
            ->limit(GlobalSearch::LIMIT)
            ->get()
            ->map(fn (AttendanceRecord $record): SearchResult => $this->toResult($record))
            ->values();
    }

    private function toResult(AttendanceRecord $record): SearchResult
    {
        // Times on the clock the day was judged on (ADR 0079).
        $zone = filled($record->rules['timezone'] ?? null) ? $record->rules['timezone'] : null;
        $time = fn ($at): ?string => $at !== null ? OrganizationClock::local(CarbonImmutable::instance($at), $zone)->format('g:i A') : null;
        $in = $time($record->first_in_at);
        $out = $time($record->last_out_at);

        $hint = Str::headline((string) $record->status);

        if ($record->late_minutes > 0 && $record->status !== 'late') {
            $hint .= ', late';
        }

        return new SearchResult(
            id: 'attendance:'.$record->id,
            title: $record->employee->full_name,
            subtitle: trim($record->work_date->format('D, M j, Y').($in !== null ? " · {$in}".($out !== null ? " – {$out}" : '') : '')),
            hint: $hint,
            href: route('attendance.index', [
                'date' => $record->work_date->toDateString(),
                'search' => $record->employee->employee_no,
                'open' => $record->hashid,
            ], absolute: false),
        );
    }
}
