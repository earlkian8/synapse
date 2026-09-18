<?php

namespace App\Support\Attendance;

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\WorkSchedule;
use App\Support\HolidayCalendar;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * The canonical punch engine. Every clock action — web self-service, the mobile
 * API and the assistant — goes through {@see punch()} so the transition rules,
 * schedule snapshot and recomputation live in exactly one place.
 *
 * Three rules hold on every path (ADR 0036):
 *
 *  - **A day is the organisation's day.** "Today", a shift's start and the date a
 *    punch is filed under are read on the tenant's clock ({@see OrganizationClock}),
 *    never on UTC's.
 *  - **A day is anchored to its shift.** A clock-out at 06:00 closes the night
 *    shift that opened at 22:00 the evening before; it does not open a day of its
 *    own ({@see workDateFor()}).
 *  - **A rejected punch writes nothing.** The day's state is checked against a
 *    record that is only saved once the punch has been accepted.
 *
 * Which shift a day is judged against is never read off the employee's record:
 * it comes from {@see ShiftResolver}, which walks roster override → dated
 * assignment → department → organisation → fallback (ADR 0037). A day is then
 * judged by the rules frozen onto it when it opened ({@see DayRules}) — the
 * shift, the holiday and the attendance policy {@see PolicyResolver} chose
 * (ADR 0038); {@see reapplySchedule()} is the one deliberate way those change.
 *
 * The punch windows are the policy's too: how early a clock-in still counts
 * towards a shift, and how long an open shift keeps claiming punches.
 */
class AttendanceClock
{
    /**
     * The longest any policy lets an open shift claim punches — how far back
     * {@see openShift()} looks before asking the day's own policy.
     */
    private const LONGEST_SHIFT_SPAN_MINUTES = 1440;

    public function __construct(
        private readonly ShiftResolver $shifts = new ShiftResolver,
        private readonly PolicyResolver $policies = new PolicyResolver,
    ) {}

    /**
     * Record a punch for an employee and return the (recomputed, saved) day record.
     *
     * @param  array{source?: string, latitude?: float|string|null, longitude?: float|string|null, accuracy?: float|string|null, photo?: string|null, note?: string|null, recorded_by?: int|null, punched_at?: \DateTimeInterface|string|null}  $context
     *
     * @throws AttendancePunchException when the punch is invalid for the day's state
     */
    public function punch(Employee $employee, string $type, array $context = []): AttendanceRecord
    {
        if (! in_array($type, AttendancePunch::TYPES, true)) {
            throw new AttendancePunchException('That punch type is not recognised.');
        }

        $at = (isset($context['punched_at']) ? CarbonImmutable::parse($context['punched_at']) : CarbonImmutable::now())->utc();

        return DB::transaction(function () use ($employee, $type, $context, $at): AttendanceRecord {
            // One punch at a time per employee: two taps racing each other must not
            // both pass the state check below, nor both open the same day.
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first(['id']);

            $date = $this->workDateFor($employee, $at, $type);
            $record = $this->findRecord($employee->id, $date, lock: true) ?? $this->newRecord($employee, $date);

            // Checked before anything is written, so a refused punch leaves no row.
            $this->assertAllowed($record, $type);

            if (! $record->exists) {
                $record->save();
            }

            $record->punches()->create([
                'employee_id' => $employee->id,
                'type' => $type,
                'punched_at' => $at,
                'source' => $context['source'] ?? 'web',
                'latitude' => $context['latitude'] ?? null,
                'longitude' => $context['longitude'] ?? null,
                'accuracy' => $context['accuracy'] ?? null,
                'photo' => $context['photo'] ?? null,
                'note' => $context['note'] ?? null,
                'recorded_by' => $context['recorded_by'] ?? null,
            ]);

            $this->refresh($record);

            return $record;
        });
    }

    /**
     * Which work date a punch at `$at` belongs to. In order:
     *
     *  1. **An open shift.** If the employee clocked in within that day's
     *     policy's `max_shift_span_minutes` (sixteen hours unless a policy says
     *     otherwise) and has not clocked out, the punch belongs to that day,
     *     whatever the calendar now says. This alone lets a night shift clock
     *     out, and a double shift run past midnight.
     *  2. **A shift about to start, or under way.** A clock-in is filed under
     *     today's or yesterday's shift (the organisation's dates) when it falls in
     *     that working day's window — from the policy's `early_clock_in_minutes`
     *     (four hours by default) before the start to the end. A 21:30 clock-in
     *     for a 22:00 shift belongs to that shift; so does a late one at 00:30. A
     *     rest day has no shift to claim a clock-in from another date.
     *  3. **Otherwise**, the organisation's calendar date at that instant.
     */
    public function workDateFor(Employee $employee, CarbonInterface $at, string $type = 'clock_in'): string
    {
        $at = CarbonImmutable::instance($at)->utc();

        $open = $this->openShift($employee, $at);

        if ($open !== null) {
            return $open->work_date->toDateString();
        }

        $today = OrganizationClock::localDate($at);

        if ($type === 'clock_in') {
            $yesterday = CarbonImmutable::parse($today)->subDay()->toDateString();

            foreach ([$today, $yesterday] as $date) {
                if ($this->withinShiftWindow($employee, $date, $at)) {
                    return $date;
                }
            }
        }

        return $today;
    }

    /**
     * Find (or create) the employee's record for the given date, freezing the
     * schedule and holiday it will be judged by the first time the day is touched.
     * For HR's explicit entry of a day — a punch goes through {@see punch()}.
     */
    public function openRecord(Employee $employee, string $date): AttendanceRecord
    {
        $record = $this->findRecord($employee->id, $date);

        if ($record !== null) {
            return $record;
        }

        $record = $this->newRecord($employee, $date);
        $record->save();

        return $record;
    }

    /**
     * The employee's record for a date for **display** — the existing row (with its
     * punches), or a transient unsaved one with the status computed, so merely
     * viewing the clock never writes an empty row. Persist only on an actual punch.
     */
    public function displayRecord(Employee $employee, string $date): AttendanceRecord
    {
        $record = AttendanceRecord::query()
            ->with('punches')
            ->where('employee_id', $employee->id)
            ->whereDate('work_date', $date)
            ->first();

        if ($record) {
            return $record;
        }

        $record = $this->newRecord($employee, $date);
        $rules = $this->rulesFor($record);

        AttendanceCalculator::recompute($record, $rules, $this->contextFor($record, $rules));

        return $record;
    }

    /**
     * The day an employee's clock card shows right now: the shift they are on, or
     * the day their next clock-in would open. A night-shift worker at 02:00 sees
     * the shift they started last night, not an empty new date.
     */
    public function currentRecord(Employee $employee): AttendanceRecord
    {
        return $this->displayRecord($employee, $this->workDateFor($employee, CarbonImmutable::now(), 'clock_in'));
    }

    /**
     * Replace a record's punches with HR-entered manual times, mark it manual and
     * recompute. Absent fields are simply omitted (e.g. a no-show keeps no punches).
     *
     * Times are clock-face readings on the organisation's clock for the record's
     * work date. A reading earlier than the one before it is the next morning, so a
     * night shift is entered the way it is worked: in 22:00, out 06:00.
     *
     * @param  array{time_in?: ?string, break_start?: ?string, break_end?: ?string, time_out?: ?string}  $times  Clock-face "HH:MM" values.
     */
    public function applyManualPunches(AttendanceRecord $record, array $times, int $recordedBy): void
    {
        $record->punches()->delete();

        $date = $record->work_date->toDateString();
        $nextDate = CarbonImmutable::parse($date)->addDay()->toDateString();
        $map = ['time_in' => 'clock_in', 'break_start' => 'break_start', 'break_end' => 'break_end', 'time_out' => 'clock_out'];
        $previous = null;

        foreach ($map as $field => $type) {
            $time = trim((string) ($times[$field] ?? ''));

            if ($time === '') {
                continue;
            }

            $at = OrganizationClock::at($date, $time);

            if ($previous !== null && $at->lt($previous)) {
                $at = OrganizationClock::at($nextDate, $time);
            }

            $record->punches()->create([
                'employee_id' => $record->employee_id,
                'type' => $type,
                'punched_at' => $at,
                'source' => 'manual',
                'recorded_by' => $recordedBy,
            ]);

            $previous = $at;
        }

        $record->is_manual = true;
        $this->refresh($record);
    }

    /**
     * Reload the punches and recompute every derived field, then persist.
     */
    public function refresh(AttendanceRecord $record): void
    {
        $this->evaluate($record);
        $record->save();
    }

    /**
     * Recompute every derived field from the record's punches and its frozen
     * rules, without saving. A record from before snapshots existed is given one
     * first ({@see fillSnapshot()}).
     */
    public function evaluate(AttendanceRecord $record): void
    {
        $rules = $this->rulesFor($record);
        $record->load('punches');

        AttendanceCalculator::recompute($record, $rules, $this->contextFor($record, $rules));
    }

    /**
     * What the calculator needs beyond the punches and the rules. The week's and
     * the month's earlier figures are read only when the day's policy uses them —
     * weekly overtime, a monthly grace allowance — so a company on anything else
     * pays nothing for them.
     *
     * Both read the days as they are saved, so re-judging a stretch of days must
     * go in date order ({@see reapplyMany()} does).
     */
    public function contextFor(AttendanceRecord $record, DayRules $rules): DayContext
    {
        $date = CarbonImmutable::parse($record->work_date->toDateString());
        $before = $date->subDay()->toDateString();

        $sum = fn (string $column, CarbonImmutable $from): int => $from->gt($date->subDay())
            ? 0
            : (int) AttendanceRecord::query()
                ->where('employee_id', $record->employee_id)
                ->whereDate('work_date', '>=', $from->toDateString())
                ->whereDate('work_date', '<=', $before)
                ->sum($column);

        return new DayContext(
            scheduledStart: $record->scheduled_start_at !== null ? CarbonImmutable::instance($record->scheduled_start_at)->utc() : null,
            scheduledEnd: $record->scheduled_end_at !== null ? CarbonImmutable::instance($record->scheduled_end_at)->utc() : null,
            timezone: OrganizationClock::timezone(),
            onApprovedLeave: $this->isOnApprovedLeave($record->employee_id, $date->toDateString()),
            weekRegularMinutesBefore: $rules->policy->needsWeekContext()
                ? $sum('regular_minutes', $date->startOfWeek(CarbonInterface::MONDAY))
                : 0,
            monthExcusedLateMinutesBefore: $rules->policy->needsMonthContext()
                ? $sum('excused_late_minutes', $date->startOfMonth())
                : 0,
        );
    }

    /**
     * Re-judge a day by the employee's **current** schedule, attendance policy and
     * holiday calendar — the one deliberate way a day's snapshot changes. Saves, and returns whether
     * anything about the day moved. The caller logs it.
     *
     * @param  array<string, Holiday>|null  $holidays  The range's holidays keyed by "Y-m-d", when the caller has already loaded them.
     */
    public function reapplySchedule(AttendanceRecord $record, ?array $holidays = null): bool
    {
        $record->loadMissing('employee');
        $date = $record->work_date->toDateString();

        $shift = $record->employee !== null
            ? $this->shifts->for($record->employee, $date)
            : ResolvedShift::fallback($date);

        $this->snapshot(
            $record,
            $shift,
            $holidays !== null ? ($holidays[$date] ?? null) : HolidayCalendar::on($date),
            $record->employee !== null ? $this->policies->for($record->employee, $shift) : null,
        );
        $this->evaluate($record);

        $changed = $record->isDirty();
        $record->save();

        return $changed;
    }

    /**
     * Re-judge a whole chunk of days by the schedules and policies that apply to
     * them now, resolving every employee's shifts and policies across the chunk's
     * range in one go. Returns how many days actually moved. The caller logs it.
     *
     * Days are judged in date order, because weekly overtime and a monthly grace
     * allowance read the days before them as they have just been saved.
     *
     * Doing this record by record would ask the resolver the same five questions
     * for every row; a period-wide re-apply over a month of a 200-person company
     * is 6,000 days.
     *
     * @param  EloquentCollection<int, AttendanceRecord>  $records  With `employee` loaded.
     * @param  array<string, Holiday>  $holidays  The range's holidays keyed by "Y-m-d".
     */
    public function reapplyMany(EloquentCollection $records, array $holidays): int
    {
        if ($records->isEmpty()) {
            return 0;
        }

        $dates = $records->map(fn (AttendanceRecord $record): string => $record->work_date->toDateString());
        $employees = $records->pluck('employee')->filter()->unique('id')->values();
        $shifts = $this->shifts->forMany($employees, (string) $dates->min(), (string) $dates->max());
        $policies = $this->policies->forMany($employees, $shifts);

        $changed = 0;

        foreach ($records->sortBy(fn (AttendanceRecord $record): string => $record->work_date->toDateString().'-'.$record->id) as $record) {
            $date = $record->work_date->toDateString();

            $this->snapshot(
                $record,
                $shifts[$record->employee_id][$date] ?? ResolvedShift::fallback($date),
                $holidays[$date] ?? null,
                $policies[$record->employee_id][$date] ?? null,
            );
            $this->evaluate($record);

            if ($record->isDirty()) {
                $changed++;
            }

            $record->save();
        }

        return $changed;
    }

    /**
     * Freeze a resolved shift onto a record: which schedule applied, its
     * clock-face edges, the instants they fall on for this work date in the
     * organisation's zone, and the rules the day is judged by — the attendance
     * policy among them. For a split shift the edges are the first segment's start
     * and the last segment's end; the segments themselves live in the rules.
     */
    public function snapshot(AttendanceRecord $record, ResolvedShift $shift, ?Holiday $holiday, ?ResolvedPolicy $policy = null): void
    {
        $record->work_schedule_id = $shift->scheduleId;
        $record->scheduled_start = $shift->startTime();
        $record->scheduled_end = $shift->endTime();
        $record->scheduled_start_at = $shift->startsAt();
        $record->scheduled_end_at = $shift->endsAt();
        $record->rules = DayRules::fromShift($shift, $holiday, $policy)->toArray();
    }

    /**
     * The shift an employee is due to work on a date — the resolver's answer,
     * reached through the engine so callers need only one collaborator.
     */
    public function shiftFor(Employee $employee, string $date): ResolvedShift
    {
        return $this->shifts->for($employee, $date);
    }

    /**
     * The attendance policy an employee's shift is judged by (ADR 0038).
     */
    public function policyFor(Employee $employee, ResolvedShift $shift): ResolvedPolicy
    {
        return $this->policies->for($employee, $shift);
    }

    /**
     * Give a record written before snapshots existed the snapshot it should have
     * had, from what it did record: the times it copied, and the schedule it names
     * (archived or not) — never the employee's schedule today. Does not save;
     * returns whether anything was filled.
     *
     * @param  array<string, Holiday>|null  $holidays  The range's holidays keyed by "Y-m-d", when the caller has already loaded them.
     */
    public function fillSnapshot(AttendanceRecord $record, ?array $holidays = null): bool
    {
        $date = $record->work_date->toDateString();
        $filled = false;

        if ($record->scheduled_start_at === null && $record->scheduled_end_at === null
            && ($record->scheduled_start !== null || $record->scheduled_end !== null)) {
            [$start, $end] = self::shiftInstants($date, $record->scheduled_start, $record->scheduled_end);

            $record->scheduled_start_at = $start;
            $record->scheduled_end_at = $end;
            $filled = true;
        }

        if ($record->rules === null) {
            $holiday = $holidays !== null ? ($holidays[$date] ?? null) : HolidayCalendar::on($date);

            $record->rules = DayRules::fromShift($this->legacyShift($record, $date), $holiday)->toArray();
            $filled = true;
        }

        return $filled;
    }

    /**
     * The instants a shift's clock-face times fall on for a work date, in the
     * organisation's zone. An end at or before the start is the next morning — a
     * 22:00–06:00 shift ends on the following day.
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function shiftInstants(string $date, ?string $start, ?string $end): array
    {
        $start = trim((string) $start);
        $end = trim((string) $end);

        $startAt = $start === '' ? null : OrganizationClock::at($date, $start);
        $endAt = $end === '' ? null : OrganizationClock::at($date, $end);

        if ($startAt !== null && $endAt !== null && $endAt->lte($startAt)) {
            $endAt = OrganizationClock::at(CarbonImmutable::parse($date)->addDay()->toDateString(), $end);
        }

        return [$startAt, $endAt];
    }

    /**
     * The punch the UI should offer next given the day's state, or null when the
     * day is complete (clocked out).
     */
    public function nextExpected(AttendanceRecord $record): ?string
    {
        $state = $this->state($record);

        if (! $state['onClock']) {
            return $record->last_out_at ? null : 'clock_in';
        }

        return $state['onBreak'] ? 'break_end' : 'clock_out';
    }

    /**
     * Every punch valid for the day's current state.
     *
     * @return list<string>
     */
    public function allowed(AttendanceRecord $record): array
    {
        $state = $this->state($record);

        if (! $state['onClock']) {
            return ['clock_in'];
        }

        return $state['onBreak'] ? ['break_end', 'clock_out'] : ['break_start', 'clock_out'];
    }

    /**
     * @throws AttendancePunchException
     */
    private function assertAllowed(AttendanceRecord $record, string $type): void
    {
        if (in_array($type, $this->allowed($record), true)) {
            return;
        }

        $state = $this->state($record);

        throw new AttendancePunchException(match (true) {
            $type === 'clock_in' && $state['onClock'] => "You're already clocked in.",
            $type === 'clock_out' && ! $state['onClock'] => 'You need to clock in first.',
            $type === 'break_start' && ! $state['onClock'] => 'Clock in before starting a break.',
            $type === 'break_start' && $state['onBreak'] => "You're already on a break.",
            $type === 'break_end' && ! $state['onBreak'] => "You're not on a break.",
            default => 'That punch is not allowed right now.',
        });
    }

    /**
     * Replay the day's punches to the current on-clock / on-break state.
     *
     * @return array{onClock: bool, onBreak: bool}
     */
    private function state(AttendanceRecord $record): array
    {
        $onClock = false;
        $onBreak = false;

        // A transient (unsaved) record carries its punches as a loaded relation;
        // a persisted one is queried fresh so the state reflects the database.
        $punches = $record->exists
            ? $record->punches()->orderBy('punched_at')->orderBy('id')->get()
            : $record->punches->sortBy(['punched_at', 'id']);

        foreach ($punches as $punch) {
            match ($punch->type) {
                'clock_in' => $onClock = true,
                'clock_out' => [$onClock, $onBreak] = [false, false],
                'break_start' => $onBreak = true,
                'break_end' => $onBreak = false,
                default => null,
            };
        }

        return ['onClock' => $onClock, 'onBreak' => $onBreak];
    }

    /**
     * The employee's most recent day, when it is still open and began recently
     * enough — by the span its own policy allows — for a punch now to belong to it.
     */
    private function openShift(Employee $employee, CarbonImmutable $at): ?AttendanceRecord
    {
        $latest = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereNotNull('first_in_at')
            ->whereBetween('first_in_at', [$at->subMinutes(self::LONGEST_SHIFT_SPAN_MINUTES), $at])
            ->orderByDesc('first_in_at')
            ->first();

        if ($latest === null || ! $this->state($latest)['onClock']) {
            return null;
        }

        $span = $this->rulesFor($latest)->policy->maxShiftSpanMinutes;

        return $latest->first_in_at->getTimestamp() >= $at->getTimestamp() - $span * 60 ? $latest : null;
    }

    /**
     * Whether an instant falls in a working day's shift window — from the
     * policy's early clock-in allowance before the shift starts until it ends.
     * Read from the day's snapshot when the day already exists, otherwise from the
     * shift and policy the resolvers give.
     */
    private function withinShiftWindow(Employee $employee, string $date, CarbonImmutable $at): bool
    {
        $record = $this->findRecord($employee->id, $date);

        if ($record?->scheduled_start_at !== null && $record->scheduled_end_at !== null) {
            // The day is already open: judge it by what it was opened with.
            $start = $record->scheduled_start_at;
            $end = $record->scheduled_end_at;
            $rules = $this->rulesFor($record);
            $working = $rules->isWorkingDay;
            $early = $rules->policy->earlyClockInMinutes;
        } else {
            $shift = $this->shifts->for($employee, $date);
            // A flexible schedule states the window it accepts punches in; every
            // other type is judged against the shift itself.
            [$start, $end] = $shift->acceptWindow();
            $working = $shift->isWorkingDay;
            $early = $working && $start !== null
                ? $this->policies->for($employee, $shift)->settings->earlyClockInMinutes
                : 0;
        }

        if (! $working || $start === null || $end === null) {
            return false;
        }

        return $at->getTimestamp() >= $start->getTimestamp() - $early * 60
            && $at->getTimestamp() <= $end->getTimestamp();
    }

    /**
     * The shift a record from before the resolver existed was judged by — read
     * from what it copied onto itself, never from the employee's schedule today.
     */
    private function legacyShift(AttendanceRecord $record, string $date): ResolvedShift
    {
        $start = WorkSchedule::clockFace($record->scheduled_start);
        $end = WorkSchedule::clockFace($record->scheduled_end);
        $schedule = $record->work_schedule_id !== null
            ? WorkSchedule::withTrashed()->find($record->work_schedule_id)
            : null;

        if ($start === null || $end === null) {
            return ResolvedShift::fallback($date);
        }

        return new ResolvedShift(
            date: $date,
            type: 'fixed',
            isWorkingDay: true,
            segments: [['start' => $start, 'end' => $end]],
            requiredMinutes: $schedule?->required_hours !== null
                ? (int) round((float) $schedule->required_hours * 60)
                : DayRules::DEFAULT_REQUIRED_MINUTES,
            graceMinutes: (int) ($schedule?->grace_minutes ?? 0),
            unpaidBreakMinutes: 0,
            source: $schedule !== null ? 'employee' : 'fallback',
            scheduleId: $schedule?->id,
            scheduleName: $schedule?->name,
        );
    }

    /**
     * The rules a record is judged by — its snapshot, given one first if it is a
     * record from before snapshots existed.
     */
    private function rulesFor(AttendanceRecord $record): DayRules
    {
        $this->fillSnapshot($record);

        return DayRules::fromArray($record->rules);
    }

    private function findRecord(int $employeeId, string $date, bool $lock = false): ?AttendanceRecord
    {
        return AttendanceRecord::query()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $date)
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * An unsaved record for the employee's day with the rules it will be judged by
     * already frozen onto it — saved only once something is actually recorded.
     */
    private function newRecord(Employee $employee, string $date): AttendanceRecord
    {
        $record = new AttendanceRecord([
            'employee_id' => $employee->id,
            'work_date' => $date,
            'status' => 'absent',
        ]);

        $shift = $this->shifts->for($employee, $date);

        $this->snapshot($record, $shift, HolidayCalendar::on($date), $this->policies->for($employee, $shift));
        $record->setRelation('punches', new EloquentCollection);

        return $record;
    }

    private function isOnApprovedLeave(int $employeeId, string $date): bool
    {
        return LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->exists();
    }
}
