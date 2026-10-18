<?php

namespace App\Support\Search;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\EmployeeCertification;
use App\Models\EventAttendee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\TrainingEnrollment;
use App\Models\User;
use App\Support\HolidayCalendar;
use App\Support\OrganizationClock;
use App\Support\Training\TrainingCertificates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The mobile app's search (ADR 0081): one's **own** records, never anybody
 * else's — leave requests by type, status or date; days by date or kind ("late
 * in october"); invitations by title or place; awards by name; and the holidays
 * one keeps. Each result names the app screen that opens it, rather than a web
 * address. The app's screens themselves are found on the phone, without asking.
 *
 * Reading changes nothing, so nothing here is activity-logged.
 */
final class MySearch
{
    /** Leave statuses a word can name. */
    private const LEAVE_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'];

    /**
     * @return list<array{key: string, label: string, items: list<array<string, mixed>>}>
     */
    public function search(User $user, Employee $employee, string $query): array
    {
        $words = GlobalSearch::words($query);

        if ($words === []) {
            return [];
        }

        $parsed = AttendanceSearchQuery::parse($words, OrganizationClock::today());

        return array_values(array_filter([
            $this->group('leave', 'Leave', $this->leave($employee, $words, $parsed)),
            $this->group('attendance', 'Attendance', $this->attendance($employee, $parsed)),
            $user->can('events.respond') ? $this->group('events', 'Events', $this->events($employee, $words)) : null,
            $this->group('awards', 'Awards', $this->awards($employee, $words)),
            $this->group('holidays', 'Holidays', $this->holidays($employee, $words)),
            $user->can('training.participate') ? $this->group('training', 'Training', $this->training($employee, $words)) : null,
        ]));
    }

    /**
     * Own leave requests: every word must name the type or the status (or be
     * "leave"); a date or month in the words keeps requests that touch it.
     *
     * @param  list<string>  $words
     * @return list<array<string, mixed>>
     */
    private function leave(Employee $employee, array $words, AttendanceSearchQuery $parsed): array
    {
        $remaining = array_values(array_filter($parsed->words, fn (string $word): bool => ! in_array($word, ['leave', 'leaves', 'request', 'requests'], true)));
        $mentionsLeave = array_intersect($words, ['leave', 'leaves', 'vacation', 'sick']) !== [];

        if ($remaining === [] && $parsed->from === null && ! $mentionsLeave) {
            return [];
        }

        $like = LeaveRequest::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->with('type:id,name')
            ->when($parsed->from !== null, fn (Builder $q) => $q
                ->whereDate('start_date', '<=', $parsed->to)
                ->whereDate('end_date', '>=', $parsed->from));

        foreach ($remaining as $word) {
            $query->where(fn (Builder $q) => in_array($word, self::LEAVE_STATUSES, true)
                ? $q->where('status', $word)
                : $q->whereHas('type', fn (Builder $type) => $type->where('name', $like, '%'.GlobalSearch::escapeLike($word).'%')));
        }

        return $query->orderByDesc('start_date')->limit(GlobalSearch::LIMIT)->get()
            ->map(fn (LeaveRequest $leave): array => [
                'id' => 'leave:'.$leave->id,
                'title' => $leave->type?->name ?? 'Leave',
                'subtitle' => $leave->start_date->isSameDay($leave->end_date)
                    ? $leave->start_date->format('D, M j, Y')
                    : $leave->start_date->format('M j').' – '.$leave->end_date->format('M j, Y'),
                'hint' => Str::headline((string) $leave->status),
                'target' => ['screen' => 'leave', 'id' => $leave->id],
            ])
            ->all();
    }

    /**
     * Own days, when the words say attendance — a day, a month or a kind of day.
     *
     * @return list<array<string, mixed>>
     */
    private function attendance(Employee $employee, AttendanceSearchQuery $parsed): array
    {
        if (! $parsed->cued || ($parsed->from === null && $parsed->kinds === [] && $parsed->words !== [])) {
            return [];
        }

        return AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->when($parsed->from !== null, fn (Builder $q) => $q->whereDate('work_date', '>=', $parsed->from)->whereDate('work_date', '<=', $parsed->to))
            ->when($parsed->kinds !== [], fn (Builder $q) => $q->where(function (Builder $q) use ($parsed): void {
                foreach ($parsed->kinds as $kind) {
                    match ($kind) {
                        'late' => $q->orWhere('late_minutes', '>', 0),
                        'undertime' => $q->orWhere('undertime_minutes', '>', 0),
                        'overtime' => $q->orWhere('overtime_minutes', '>', 0),
                        'present' => $q->orWhereIn('status', AttendanceRecord::PRESENT_STATUSES),
                        default => $q->orWhere('status', $kind),
                    };
                }
            }))
            ->orderByDesc('work_date')
            ->limit(GlobalSearch::LIMIT)
            ->get(['id', 'work_date', 'status', 'first_in_at', 'last_out_at', 'late_minutes', 'rules'])
            ->map(function (AttendanceRecord $record): array {
                $zone = filled($record->rules['timezone'] ?? null) ? $record->rules['timezone'] : null;
                $in = $record->first_in_at !== null ? OrganizationClock::local(CarbonImmutable::instance($record->first_in_at), $zone)->format('g:i A') : null;
                $out = $record->last_out_at !== null ? OrganizationClock::local(CarbonImmutable::instance($record->last_out_at), $zone)->format('g:i A') : null;

                return [
                    'id' => 'attendance:'.$record->id,
                    'title' => $record->work_date->format('l, M j, Y'),
                    'subtitle' => $in !== null ? $in.($out !== null ? ' – '.$out : '') : null,
                    'hint' => Str::headline((string) $record->status),
                    'target' => ['screen' => 'attendance', 'date' => $record->work_date->toDateString()],
                ];
            })
            ->all();
    }

    /**
     * Own invitations, by the event's title or place.
     *
     * @param  list<string>  $words
     * @return list<array<string, mixed>>
     */
    private function events(Employee $employee, array $words): array
    {
        return EventAttendee::query()
            ->where('employee_id', $employee->id)
            ->whereHas('event', fn (Builder $event) => GlobalSearch::matchEveryWord($event, $words))
            ->with('event:id,title,location,starts_at')
            ->get()
            ->sortByDesc(fn (EventAttendee $row): int => $row->event->starts_at->getTimestamp())
            ->take(GlobalSearch::LIMIT)
            ->map(fn (EventAttendee $row): array => [
                'id' => 'event:'.$row->event->id,
                'title' => $row->event->title,
                'subtitle' => trim(OrganizationClock::local($row->event->starts_at)->format('D, M j · g:i A').($row->event->location ? ' · '.$row->event->location : '')),
                'hint' => Str::headline((string) $row->response),
                'target' => ['screen' => 'event', 'id' => $row->event->hashid],
            ])
            ->values()
            ->all();
    }

    /**
     * Own awards, by the award's name.
     *
     * @param  list<string>  $words
     * @return list<array<string, mixed>>
     */
    private function awards(Employee $employee, array $words): array
    {
        $like = EmployeeAward::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $query = EmployeeAward::query()->where('employee_id', $employee->id)->with('awardType:id,name');

        foreach ($words as $word) {
            $query->whereHas('awardType', fn (Builder $type) => $type->where('name', $like, '%'.GlobalSearch::escapeLike($word).'%'));
        }

        return $query->orderByDesc('awarded_on')->limit(GlobalSearch::LIMIT)->get()
            ->map(fn (EmployeeAward $award): array => [
                'id' => 'award:'.$award->id,
                'title' => $award->awardType?->name ?? 'Award',
                'subtitle' => $award->awarded_on?->format('M j, Y'),
                'hint' => null,
                'target' => ['screen' => 'awards'],
            ])
            ->all();
    }

    /**
     * The holidays the person keeps — their site's local ones included — by
     * name, on their next date.
     *
     * @param  list<string>  $words
     * @return list<array<string, mixed>>
     */
    private function holidays(Employee $employee, array $words): array
    {
        // "holidays" alone, or "next holiday", lists the coming ones.
        $named = array_values(array_diff($words, ['holiday', 'holidays', 'next', 'upcoming']));

        if ($named === [] && array_intersect($words, ['holiday', 'holidays']) === []) {
            return [];
        }

        $like = Holiday::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $query = Holiday::query()->keptAt(HolidayCalendar::siteOf($employee));

        foreach ($named as $word) {
            $query->where('name', $like, '%'.GlobalSearch::escapeLike($word).'%');
        }

        $today = CarbonImmutable::parse(OrganizationClock::today());

        return $query->get()
            ->map(fn (Holiday $holiday): array => ['holiday' => $holiday, 'next' => $holiday->nextOccurrence($today)])
            ->filter(fn (array $row): bool => $row['next'] !== null)
            ->sortBy(fn (array $row): string => $row['next']->toDateString())
            ->take(GlobalSearch::LIMIT)
            ->map(fn (array $row): array => [
                'id' => 'holiday:'.$row['holiday']->id,
                'title' => $row['holiday']->name,
                'subtitle' => $row['next']->format('l, M j, Y'),
                'hint' => Str::headline((string) $row['holiday']->type),
                'target' => ['screen' => 'holidays'],
            ])
            ->values()
            ->all();
    }

    /**
     * Own programs (requests included) by the program's name or provider, and
     * own certificates by name. "training" or "certificates" alone lists them.
     *
     * @param  list<string>  $words
     * @return list<array<string, mixed>>
     */
    private function training(Employee $employee, array $words): array
    {
        $cues = ['training', 'trainings', 'program', 'programs', 'course', 'courses', 'certificate', 'certificates', 'cert', 'certs'];
        $named = array_values(array_diff($words, $cues));

        if ($named === [] && array_intersect($words, $cues) === []) {
            return [];
        }

        $onlyCertificates = $named === [] && array_intersect($words, ['certificate', 'certificates', 'cert', 'certs']) !== [];

        $programs = $onlyCertificates ? collect() : TrainingEnrollment::query()
            ->where('employee_id', $employee->id)
            ->whereHas('program', fn (Builder $program) => GlobalSearch::matchEveryWord($program, $named))
            ->with('program:id,name,provider,start_date,end_date')
            ->latest('id')
            ->limit(GlobalSearch::LIMIT)
            ->get()
            ->map(fn (TrainingEnrollment $enrollment): array => [
                'id' => 'training:'.$enrollment->id,
                'title' => $enrollment->program->name,
                'subtitle' => $enrollment->program->provider,
                'hint' => $enrollment->status === 'requested' ? 'Asked to join' : Str::headline((string) $enrollment->status),
                'target' => ['screen' => 'training', 'id' => $enrollment->program->hashid],
            ]);

        // Every certificate of theirs is read, so a renewed one says so; a person holds few.
        $theirs = EmployeeCertification::query()->fromTraining()->where('employee_id', $employee->id)->latest('issued_date')->latest('id')->get();
        $renewed = TrainingCertificates::renewedIds($theirs);

        $certificates = $theirs
            ->filter(fn (EmployeeCertification $certificate): bool => collect($named)->every(fn (string $word): bool => Str::contains(Str::lower($certificate->name), $word)))
            ->take(GlobalSearch::LIMIT)
            ->map(fn (EmployeeCertification $certificate): array => [
                'id' => 'certificate:'.$certificate->id,
                'title' => $certificate->name,
                'subtitle' => trim('Certificate '.($certificate->certificate_no ?? '')),
                'hint' => Str::headline(TrainingCertificates::standing($certificate, isset($renewed[$certificate->id]))),
                'target' => ['screen' => 'certificate', 'id' => $certificate->id],
            ]);

        return $programs->concat($certificates)->take(GlobalSearch::LIMIT * 2)->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{key: string, label: string, items: list<array<string, mixed>>}|null
     */
    private function group(string $key, string $label, array $items): ?array
    {
        return $items === [] ? null : ['key' => $key, 'label' => $label, 'items' => $items];
    }
}
