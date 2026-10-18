<?php

namespace App\Queries;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\TrainingEnrollment;
use App\Models\TrainingSession;
use App\Models\WorkLocation;
use App\Support\Attendance\ResolvedShift;
use App\Support\Attendance\ShiftResolver;
use App\Support\HolidayCalendar;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One person's own week, as the app's My schedule shows it (ADR 0083): the
 * shift for each day, the holiday on it, any leave they have filed for it, and
 * the training sessions they hold a seat at (ADR 0094).
 *
 * It is the roster board's row for one person ({@see ShiftRosterQuery}) — the
 * same {@see ShiftResolver} answer and the same holidays, a local one at their
 * site included — read on their own clock, so the plan on the phone is the plan
 * HR sees. Weeks run Monday to Sunday, as on the board.
 *
 * What it leaves out on purpose: where a shift came from in the precedence
 * chain, and the reason HR wrote on an override. The day says it was changed
 * for this date, and nothing of HR's notes.
 */
final class MySchedule
{
    /** How many days a week shows. */
    public const DAYS = 7;

    public function __construct(private readonly ShiftResolver $shifts = new ShiftResolver) {}

    /**
     * The week containing `$date` — this week on the person's clock when null.
     *
     * @return array<string, mixed>
     */
    public function week(Employee $employee, ?string $date = null): array
    {
        $zone = WorkLocation::timezoneFor($employee);
        $today = OrganizationClock::today($zone);
        $start = CarbonImmutable::parse($date ?? $today)->startOfWeek();
        $end = $start->addDays(self::DAYS - 1);

        $shifts = $this->shifts->forMany(collect([$employee]), $start->toDateString(), $end->toDateString())[$employee->id] ?? [];
        $holidays = HolidayCalendar::forEmployees(collect([$employee]), $start, $end)->for($employee->id);
        $leave = $this->leave($employee, $start, $end);
        $training = $this->training($employee, $start, $end, $zone);

        $days = [];

        for ($i = 0; $i < self::DAYS; $i++) {
            $day = $start->addDays($i);
            $key = $day->toDateString();

            $days[] = $this->day(
                $day,
                $shifts[$key] ?? ResolvedShift::fallback($key),
                $holidays[$key] ?? null,
                $leave,
                $today,
            ) + ['training' => $training[$key] ?? []];
        }

        $due = array_filter($days, fn (array $day): bool => $day['is_due']);

        return [
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
            'today' => $today,
            'timezone' => OrganizationClock::zone($zone),
            'days' => $days,
            'totals' => [
                'shifts' => count($due),
                'minutes' => array_sum(array_map(fn (array $day): int => $day['due_minutes'], $due)),
                'training' => array_sum(array_map(fn (array $day): int => count($day['training']), $days)),
            ],
        ];
    }

    /**
     * One day of the week.
     *
     * A day is *due* when the shift asks for work and nothing excuses it: a
     * non-working holiday or a whole day of approved leave. Approved half a day,
     * it is due for half the hours. Pending leave excuses nothing yet.
     *
     * @param  Collection<int, LeaveRequest>  $leave
     * @return array<string, mixed>
     */
    private function day(CarbonImmutable $day, ResolvedShift $shift, ?Holiday $holiday, Collection $leave, string $today): array
    {
        $key = $day->toDateString();
        $dayOff = $holiday !== null && in_array($holiday->type, Holiday::NON_WORKING_TYPES, true);

        // Leave only falls on a day that would have been worked. The approved
        // request wins a day two requests share.
        $request = $shift->isWorkingDay && ! $dayOff
            ? $leave->first(fn (LeaveRequest $request): bool => $this->covers($request, $day))
            : null;

        $approved = $request?->status === 'approved';
        $halfDay = $approved && $request->is_half_day;
        $due = $shift->isWorkingDay && ! $dayOff && (! $approved || $halfDay);

        return [
            'date' => $key,
            'is_today' => $key === $today,
            'is_past' => $key < $today,
            'is_working_day' => $shift->isWorkingDay,
            'type' => $shift->type,
            'label' => $shift->label(),
            'segments' => $shift->segments,
            'required_minutes' => $shift->requiredMinutes,
            'core' => $shift->type === 'flexible' && $shift->coreStart !== null && $shift->coreEnd !== null
                ? ['start' => $shift->coreStart, 'end' => $shift->coreEnd]
                : null,
            'schedule_name' => $shift->scheduleName,
            // A one-off roster entry for this date (ADR 0037), set by HR.
            'changed' => $shift->source === 'roster',
            'holiday' => $holiday === null ? null : [
                'name' => $holiday->name,
                'type' => $holiday->type,
                'day_off' => $dayOff,
            ],
            'leave' => $request === null ? null : [
                'id' => $request->id,
                'type' => $request->type?->name ?? 'Leave',
                'color' => $request->type?->color,
                'status' => $request->status,
                'half_day' => (bool) $request->is_half_day,
                'half_day_period' => $request->is_half_day ? $request->half_day_period : null,
            ],
            'is_due' => $due,
            'due_minutes' => $due ? ($halfDay ? intdiv($shift->requiredMinutes, 2) : $shift->requiredMinutes) : 0,
        ];
    }

    /**
     * The person's leave touching the week that still stands — approved first,
     * so it wins a date it shares with one still waiting.
     *
     * @return Collection<int, LeaveRequest>
     */
    private function leave(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return LeaveRequest::query()
            ->with('type:id,name,color')
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['approved', 'pending'])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->get()
            ->sortBy(fn (LeaveRequest $request): int => $request->status === 'approved' ? 0 : 1)
            ->values();
    }

    /**
     * The sessions of the programs the person holds a seat on, by the day they
     * fall on here. A session is set on the organisation's wall clock (ADR
     * 0074); a site that keeps its own clock sees it at its own hour, and on
     * its own date when the difference carries it past midnight. Training adds
     * to the day; it excuses no work.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function training(Employee $employee, CarbonImmutable $start, CarbonImmutable $end, ?string $zone): array
    {
        $programs = TrainingEnrollment::query()->where('employee_id', $employee->id)->active()->pluck('training_program_id');

        if ($programs->isEmpty()) {
            return [];
        }

        $local = OrganizationClock::zone($zone);

        return TrainingSession::query()
            ->whereIn('training_program_id', $programs)
            ->ofLivePrograms()
            // A day either side: a zone apart can move a session onto this week.
            ->whereBetween('session_date', [$start->subDay()->toDateString(), $end->addDay()->toDateString()])
            ->with('program:id,name,provider')
            ->chronological()
            ->get()
            ->map(fn (TrainingSession $session): array => [
                'session' => $session,
                'starts' => $session->startsAt()->setTimezone($local),
                'ends' => $session->endsAt()->setTimezone($local),
            ])
            ->filter(fn (array $row): bool => $row['starts']->toDateString() >= $start->toDateString() && $row['starts']->toDateString() <= $end->toDateString())
            ->sortBy(fn (array $row): int => $row['starts']->getTimestamp())
            ->groupBy(fn (array $row): string => $row['starts']->toDateString())
            ->map(fn ($rows): array => $rows->map(fn (array $row): array => [
                'id' => $row['session']->id,
                'title' => $row['session']->title,
                'starts_at' => $row['starts']->format('H:i'),
                'ends_at' => $row['ends']->format('H:i'),
                'location' => $row['session']->location,
                'program' => ['hashid' => $row['session']->program->hashid, 'name' => $row['session']->program->name],
            ])->values()->all())
            ->all();
    }

    private function covers(LeaveRequest $request, CarbonImmutable $day): bool
    {
        $key = $day->toDateString();

        return $request->start_date->toDateString() <= $key && $request->end_date->toDateString() >= $key;
    }
}
