<?php

namespace App\Support\Attendance;

use App\Models\AttendancePeriod;
use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WorkLocation;
use App\Models\WorkSchedule;
use App\Support\HolidayCalendar;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

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
 *
 * **A locked period is frozen here** (ADR 0039). Every write this engine makes
 * to a day — a punch, a manual edit, a correction, a sign-off, a re-apply, a
 * delete — asks {@see PeriodLock} first, so no caller can forget to.
 *
 * **It enforces for people and records for devices** (ADR 0040). A person on
 * the web, the phone or a kiosk is held to the day's policy — where they may
 * punch from, from which address, with a selfie, inside the fence — and to the
 * day's order ("you're already clocked in"). A scanner on a wall reports what
 * happened: its punch is recorded as it came and the evaluator flags what does
 * not add up ({@see capture()}).
 */
class AttendanceClock
{
    /**
     * The longest any policy lets an open shift claim punches — how far back
     * {@see openShift()} looks before asking the day's own policy.
     */
    private const LONGEST_SHIFT_SPAN_MINUTES = 1440;

    /** How a refusal names where a punch came from. */
    private const SOURCE_WORDS = [
        'web' => 'the web',
        'mobile' => 'the mobile app',
        'kiosk' => 'a kiosk',
        'biometric' => 'a biometric device',
        'manual' => 'entry by HR',
    ];

    public function __construct(
        private readonly ShiftResolver $shifts = new ShiftResolver,
        private readonly PolicyResolver $policies = new PolicyResolver,
    ) {}

    /**
     * Record a punch for an employee and return the (recomputed, saved) day record.
     *
     * @param  array<string, mixed>  $context  See {@see capture()}.
     *
     * @throws AttendancePunchException when the punch is refused
     */
    public function punch(Employee $employee, string $type, array $context = []): AttendanceRecord
    {
        return $this->capture($employee, $type, $context)->record;
    }

    /**
     * Capture one punch, on any path. In order, inside one transaction with a
     * row lock on the employee:
     *
     *  1. **A resend is recognised.** A punch carrying the sender's own id
     *     (`external_id` — a device's, or the id a phone gave a queued punch)
     *     that has been received before returns what was recorded the first
     *     time, and records nothing.
     *  2. **An untyped punch is inferred** — a scanner often only knows "a
     *     punch happened": out when a shift is open, otherwise in.
     *  3. The work date, the period lock and the day are found as for any punch.
     *  4. **For a person** (`record_only` unset) the day's policy is enforced:
     *     where they may punch from, how old a queued punch may be, the day's
     *     order, the web address allowlist, the selfie, and — in `block` mode —
     *     the fence. A refused punch writes nothing.
     *  5. **The punch is placed**: the nearest work location, the distance and
     *     whether it was on site, kept on the punch whatever the policy says.
     *  6. The punch is written and the day recomputed.
     *
     * Context keys, all optional: `source` (default `web`), `latitude`,
     * `longitude`, `accuracy`, `photo`, `note`, `recorded_by`, `punched_at`
     * (default now), `ip` (a web punch's client address), `attendance_device_id`,
     * `work_location_id` (where a device is), `external_id`, `offline` (the time
     * was stamped by a phone that was offline), `sent_at` (the sender's clock
     * when it sent the punch, to measure its skew) and `record_only` (a device's
     * punch: recorded, not judged).
     *
     * @param  array<string, mixed>  $context
     *
     * @throws AttendancePunchException when the punch is refused
     */
    public function capture(Employee $employee, ?string $type, array $context = []): CapturedPunch
    {
        if ($type !== null && ! in_array($type, AttendancePunch::TYPES, true)) {
            throw new AttendancePunchException('That punch type is not recognised.');
        }

        $receivedAt = CarbonImmutable::now()->utc();
        $at = (isset($context['punched_at']) ? CarbonImmutable::parse($context['punched_at']) : $receivedAt)->utc();
        $source = (string) ($context['source'] ?? 'web');
        $recordOnly = (bool) ($context['record_only'] ?? false);
        $offline = (bool) ($context['offline'] ?? false);
        $externalId = filled($context['external_id'] ?? null) ? (string) $context['external_id'] : null;
        $deviceId = isset($context['attendance_device_id']) ? (int) $context['attendance_device_id'] : null;
        $sentAt = isset($context['sent_at']) ? CarbonImmutable::parse($context['sent_at'])->utc() : null;

        return DB::transaction(function () use ($employee, $type, $context, $at, $receivedAt, $source, $recordOnly, $offline, $externalId, $deviceId, $sentAt): CapturedPunch {
            // One punch at a time per employee: two taps racing each other must not
            // both pass the state check below, nor both open the same day — and a
            // resend racing its original must find it.
            Employee::query()->whereKey($employee->getKey())->lockForUpdate()->first(['id']);

            if ($externalId !== null) {
                $seen = $this->alreadyReceived($employee, $deviceId, $externalId);

                if ($seen !== null) {
                    return $seen;
                }
            }

            $type ??= $this->inferType($employee, $at);

            $date = $this->workDateFor($employee, $at, $type);
            $this->lock()->assertOpen($date);

            $record = $this->findRecord($employee->id, $date, lock: true) ?? $this->newRecord($employee, $date);
            $policy = $this->rulesFor($record)->policy;

            // Checked before anything is written, so a refused punch leaves no row.
            if (! $recordOnly) {
                $this->assertSourceAllowed($policy, $source);

                if ($offline) {
                    $this->assertWithinOfflineWindow($policy, $at, $receivedAt);
                }

                $this->assertAllowedAt($record, $type, $at);
                $this->assertCaptureRules($policy, $source, $context);
            }

            $placement = $this->place($employee, $date, $policy, $source, $context, enforce: ! $recordOnly);

            if (! $record->exists) {
                $record->save();
            }

            $punch = $record->punches()->create([
                'employee_id' => $employee->id,
                'type' => $type,
                'punched_at' => $at,
                'source' => $source,
                'latitude' => $context['latitude'] ?? null,
                'longitude' => $context['longitude'] ?? null,
                'accuracy' => $context['accuracy'] ?? null,
                ...$placement,
                'attendance_device_id' => $deviceId,
                'external_id' => $externalId,
                'device_punched_at' => $offline ? $at : null,
                'received_at' => $receivedAt,
                'clock_skew_seconds' => $sentAt !== null ? $sentAt->getTimestamp() - $receivedAt->getTimestamp() : null,
                'photo' => $context['photo'] ?? null,
                'note' => $context['note'] ?? null,
                'recorded_by' => $context['recorded_by'] ?? null,
            ]);

            $this->refresh($record);

            return new CapturedPunch($record, $punch);
        });
    }

    /**
     * Which punch an untyped one is: the clock-out of a shift still open, or else
     * a clock-in. A scanner that only knows "somebody punched" is read the way the
     * person meant it — in, out, in, out — and a mistake is the day's anomaly to
     * flag, not the device's punch to refuse.
     */
    public function inferType(Employee $employee, CarbonInterface $at): string
    {
        return $this->openShift($employee, CarbonImmutable::instance($at)->utc()) !== null ? 'clock_out' : 'clock_in';
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
     * For HR's explicit entry of a day and an approved request — a punch goes
     * through {@see punch()}. Refused inside a locked period.
     *
     * @throws AttendanceLockedException
     */
    public function openRecord(Employee $employee, string $date): AttendanceRecord
    {
        $this->lock()->assertOpen($date);

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
     * The punches it replaces are soft-deleted, not erased (ADR 0039).
     *
     * @param  array{time_in?: ?string, break_start?: ?string, break_end?: ?string, time_out?: ?string}  $times  Clock-face "HH:MM" values.
     *
     * @throws AttendanceLockedException
     */
    public function applyManualPunches(AttendanceRecord $record, array $times, int $recordedBy): void
    {
        $this->lock()->assertOpen($record->work_date->toDateString());

        // A policy can take entry by HR away (ADR 0040); a day is then fixed
        // through a correction request, which leaves a trail.
        $this->assertSourceAllowed($this->rulesFor($record)->policy, 'manual');

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
     * Apply an approved correction request (ADR 0039). Unlike HR's manual edit,
     * which retypes the whole day, a correction changes only the punches it names:
     * a forgotten clock-out gains a clock-out and keeps the clock-in the employee
     * actually made.
     *
     * Each punch it changes is the one the day shows for that field — the first
     * clock-in, the first break, the last clock-out. The replaced punch is
     * soft-deleted and names the request; its replacement is `source =
     * correction` and names it too. A reading earlier than the punch before it is
     * the next morning, as in {@see applyManualPunches()}.
     *
     * @param  array<string, ?string>  $times  Clock-face "HH:MM" values keyed by AttendanceRequest::CORRECTION_FIELDS.
     *
     * @throws AttendanceLockedException
     */
    public function applyCorrection(AttendanceRecord $record, array $times, AttendanceRequest $request, int $recordedBy): void
    {
        $this->lock()->assertOpen($record->work_date->toDateString());

        $punches = $record->punches()->get();
        $current = [
            'time_in' => $punches->firstWhere('type', 'clock_in'),
            'break_start' => $punches->firstWhere('type', 'break_start'),
            'break_end' => $punches->firstWhere('type', 'break_end'),
            'time_out' => $punches->where('type', 'clock_out')->last(),
        ];

        $date = $record->work_date->toDateString();
        $nextDate = CarbonImmutable::parse($date)->addDay()->toDateString();
        $map = ['time_in' => 'clock_in', 'break_start' => 'break_start', 'break_end' => 'break_end', 'time_out' => 'clock_out'];
        $previous = null;

        foreach ($map as $field => $type) {
            $time = trim((string) ($times[$field] ?? ''));
            $existing = $current[$field];

            if ($time === '') {
                // Kept as it is — but it still decides whether a later reading
                // is the next morning.
                $previous = $existing !== null ? CarbonImmutable::instance($existing->punched_at)->utc() : $previous;

                continue;
            }

            $at = OrganizationClock::at($date, $time);

            if ($previous !== null && $at->lt($previous)) {
                $at = OrganizationClock::at($nextDate, $time);
            }

            if ($existing !== null) {
                $existing->forceFill(['replaced_by_request_id' => $request->id])->save();
                $existing->delete();
            }

            $record->punches()->create([
                'employee_id' => $record->employee_id,
                'type' => $type,
                'punched_at' => $at,
                'source' => 'correction',
                'recorded_by' => $recordedBy,
                'attendance_request_id' => $request->id,
            ]);

            $previous = $at;
        }

        $record->is_manual = true;
        $this->refresh($record);
    }

    /**
     * Sign a day off (ADR 0039): somebody with the authority to has looked at
     * what needed review on it. The overtime the day shows now is granted — the
     * evaluator reads the grant back on every recompute, so it survives one, and
     * overtime a later correction adds is not quietly approved with it.
     *
     * Nobody signs off their own day.
     *
     * @throws AttendanceException
     */
    public function signOff(AttendanceRecord $record, User $by): void
    {
        $this->lock()->assertOpen($record->work_date->toDateString());

        $record->loadMissing('employee:id,user_id');

        if ($record->employee?->user_id !== null && $record->employee->user_id === $by->id) {
            throw new AttendanceException("You can't sign off your own attendance.");
        }

        $record->forceFill([
            'approved_by' => $by->id,
            'approved_at' => now(),
            'signed_off_overtime_minutes' => (int) $record->overtime_minutes,
        ]);

        $this->refresh($record);
    }

    /**
     * Delete a day and its punches. Refused inside a locked period.
     *
     * @throws AttendanceLockedException
     */
    public function deleteRecord(AttendanceRecord $record): void
    {
        $this->lock()->assertOpen($record->work_date->toDateString());

        $record->delete();
    }

    /**
     * Write the record for a day nobody punched (ADR 0041), judged by a shift,
     * holiday and policy the caller has already resolved — the end-of-day job
     * resolves a whole organisation's day at once. The day comes out absent, on
     * leave, a holiday or official business, and is marked closed. An existing
     * record is returned untouched, so closing a day twice writes nothing.
     *
     * @throws AttendanceLockedException
     */
    public function materialise(Employee $employee, ResolvedShift $shift, ?Holiday $holiday, ?ResolvedPolicy $policy): AttendanceRecord
    {
        $this->lock()->assertOpen($shift->date);

        $existing = $this->findRecord($employee->id, $shift->date);

        if ($existing !== null) {
            return $existing;
        }

        $record = new AttendanceRecord([
            'employee_id' => $employee->id,
            'work_date' => $shift->date,
            'status' => 'absent',
        ]);

        $this->snapshot($record, $shift, $holiday, $policy);
        $record->closed_at = now();
        $record->save();

        $this->refresh($record);

        return $record;
    }

    /**
     * Deal with a day whose clock-out never came, as its policy says
     * (`missing_clock_out`, ADR 0041). The end-of-day job only asks once the
     * shift can no longer claim a punch — its `max_shift_span_minutes` since the
     * clock-in have passed — so this never races the person's own clock-out.
     *
     *  - `flag` — the day stays open; closing it raises `missing_clock_out`.
     *  - `auto_close_at_shift_end` / `auto_close_after_minutes` — a clock-out is
     *    written at the shift's end (or that many minutes after it), never before
     *    the day's last punch and never later than the shift could run. It is
     *    `source = system`, so the day is flagged `auto_closed` and waits for
     *    sign-off: the hours are the plan's, not the person's.
     *
     * Returns `auto_closed` or `flagged`.
     *
     * @throws AttendanceLockedException
     */
    public function closeForgottenDay(AttendanceRecord $record): string
    {
        $this->lock()->assertOpen($record->work_date->toDateString());

        $rules = $this->rulesFor($record);
        $policy = $rules->policy;
        $record->closed_at = now();

        if ($policy->missingClockOutAction === 'flag' || $record->first_in_at === null) {
            $this->refresh($record);

            return 'flagged';
        }

        $firstIn = CarbonImmutable::instance($record->first_in_at)->utc();
        $last = $record->punches()->max('punched_at');
        $lastPunch = $last !== null ? CarbonImmutable::parse($last, 'UTC') : $firstIn;

        $end = $record->scheduled_end_at !== null
            ? CarbonImmutable::instance($record->scheduled_end_at)->utc()
            : $firstIn->addMinutes($rules->requiredMinutes);

        if ($policy->missingClockOutAction === 'auto_close_after_minutes') {
            $end = $end->addMinutes($policy->missingClockOutAfterMinutes);
        }

        $latest = $firstIn->addMinutes($policy->maxShiftSpanMinutes);
        $at = $end->min($latest)->min(CarbonImmutable::now()->utc())->max($lastPunch);

        $record->punches()->create([
            'employee_id' => $record->employee_id,
            'type' => 'clock_out',
            'punched_at' => $at,
            'source' => 'system',
            'received_at' => now(),
            'note' => 'Closed automatically: no clock-out was recorded.',
        ]);

        $this->refresh($record);

        return 'auto_closed';
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

        $requests = $this->requestContext($record, $date->toDateString());

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
            grantedOvertimeMinutes: $requests['grantedOvertimeMinutes'],
            officialBusiness: $requests['officialBusiness'],
            remoteWork: $requests['remoteWork'],
            closed: $record->closed_at !== null,
        );
    }

    /**
     * What decided attendance requests say about a day (ADR 0039), in one query:
     *
     *  - **Granted overtime** — null while nobody has decided it. Once an
     *    overtime request for the day is approved or rejected, or HR signs the
     *    day off, it is the larger of the sign-off and the approved requests'
     *    minutes (a sign-off already covers what was asked for, so they are not
     *    added). A pre-approval filed before the day is matched here, whenever the
     *    day is evaluated.
     *  - **Official business** and **remote work** covering the day.
     *
     * @return array{grantedOvertimeMinutes: ?int, officialBusiness: bool, remoteWork: bool}
     */
    private function requestContext(AttendanceRecord $record, string $date): array
    {
        $requests = AttendanceRequest::query()
            ->where('employee_id', $record->employee_id)
            ->whereIn('status', ['approved', 'rejected'])
            ->overlapping($date, $date)
            ->get(['id', 'type', 'status', 'payload']);

        $overtime = $requests->where('type', 'overtime');
        $signedOff = $record->signed_off_overtime_minutes;

        $granted = $overtime->isEmpty() && $signedOff === null
            ? null
            : max(
                (int) $signedOff,
                (int) $overtime->where('status', 'approved')->sum(fn (AttendanceRequest $request): int => $request->requestedMinutes()),
            );

        $approved = $requests->where('status', 'approved');

        return [
            'grantedOvertimeMinutes' => $granted,
            'officialBusiness' => $approved->contains('type', 'official_business'),
            'remoteWork' => $approved->contains('type', 'remote_work'),
        ];
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
        $date = $record->work_date->toDateString();
        $this->lock()->assertOpen($date);

        $record->loadMissing('employee');

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
     * A day inside a locked period is left exactly as it is (ADR 0039), and
     * counted into `$skipped`.
     *
     * @param  EloquentCollection<int, AttendanceRecord>  $records  With `employee` loaded.
     * @param  array<string, Holiday>  $holidays  The range's holidays keyed by "Y-m-d".
     */
    public function reapplyMany(EloquentCollection $records, array $holidays, ?int &$skipped = null): int
    {
        $skipped = 0;

        if ($records->isEmpty()) {
            return 0;
        }

        $locked = $this->lockedPeriodsBetween(
            (string) $records->min(fn (AttendanceRecord $record): string => $record->work_date->toDateString()),
            (string) $records->max(fn (AttendanceRecord $record): string => $record->work_date->toDateString()),
        );

        $records = $records->reject(function (AttendanceRecord $record) use ($locked, &$skipped): bool {
            $isLocked = $locked->contains(fn (AttendancePeriod $period): bool => $period->covers($record->work_date->toDateString()));
            $skipped += $isLocked ? 1 : 0;

            return $isLocked;
        });

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
     * Re-read a day's holiday from the calendar (ADR 0041). A holiday is a fact
     * about the date rather than a rule the day was judged by, so adding one
     * late — or removing one added by mistake — reaches the days already
     * recorded on it; the schedule and policy in the snapshot stay as they were.
     * Does not save.
     */
    public function syncHoliday(AttendanceRecord $record, ?Holiday $holiday): void
    {
        $this->fillSnapshot($record);

        $rules = $record->rules;
        $rules['holiday_type'] = $holiday?->type;
        $rules['holiday_name'] = $holiday?->name;
        $record->rules = $rules;
    }

    /**
     * The locked periods touching [from, to], read fresh — for walking a range of
     * days that must leave the frozen ones alone.
     *
     * @return EloquentCollection<int, AttendancePeriod>
     */
    public function lockedPeriodsBetween(string $from, string $to): EloquentCollection
    {
        return AttendancePeriod::query()
            ->where('status', 'locked')
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->get();
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
     * The attendance policy a recorded day is judged by — its snapshot's.
     */
    public function policyOf(AttendanceRecord $record): AttendancePolicySettings
    {
        return $this->rulesFor($record)->policy;
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
        return $this->allowedFor($this->state($record));
    }

    /**
     * @param  array{onClock: bool, onBreak: bool}  $state
     * @return list<string>
     */
    private function allowedFor(array $state): array
    {
        if (! $state['onClock']) {
            return ['clock_in'];
        }

        return $state['onBreak'] ? ['break_end', 'clock_out'] : ['break_start', 'clock_out'];
    }

    /**
     * Replay the day's punches to the current on-clock / on-break state.
     *
     * @return array{onClock: bool, onBreak: bool}
     */
    private function state(AttendanceRecord $record): array
    {
        return $this->replay($this->orderedPunches($record));
    }

    /**
     * The day's punches in the order they happened. A transient (unsaved) record
     * carries its punches as a loaded relation; a persisted one is queried fresh
     * so the state reflects the database.
     *
     * @return Collection<int, AttendancePunch>
     */
    private function orderedPunches(AttendanceRecord $record): Collection
    {
        return $record->exists
            ? $record->punches()->orderBy('punched_at')->orderBy('id')->get()
            : $record->punches->sortBy(['punched_at', 'id'])->values();
    }

    /**
     * Walk punches from a state to the one they leave.
     *
     * @param  iterable<AttendancePunch>  $punches
     * @param  array{onClock: bool, onBreak: bool}  $from
     * @return array{onClock: bool, onBreak: bool}
     */
    private function replay(iterable $punches, array $from = ['onClock' => false, 'onBreak' => false]): array
    {
        ['onClock' => $onClock, 'onBreak' => $onBreak] = $from;

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
     * Refuse a person's punch that the day's order does not allow at the moment
     * it was made. For a punch made now that is the day's current state; a
     * punch a phone queued while offline is checked where it falls among the
     * punches already recorded, and refused when it would put a later one out of
     * order.
     *
     * @throws AttendancePunchException
     */
    private function assertAllowedAt(AttendanceRecord $record, string $type, CarbonImmutable $at): void
    {
        $punches = $this->orderedPunches($record);
        $before = $punches->filter(fn (AttendancePunch $punch): bool => $punch->punched_at->getTimestamp() <= $at->getTimestamp());
        $state = $this->replay($before);

        if (! in_array($type, $this->allowedFor($state), true)) {
            throw new AttendancePunchException(match (true) {
                $type === 'clock_in' && $state['onClock'] => "You're already clocked in.",
                $type === 'clock_out' && ! $state['onClock'] => 'You need to clock in first.',
                $type === 'break_start' && ! $state['onClock'] => 'Clock in before starting a break.',
                $type === 'break_start' && $state['onBreak'] => "You're already on a break.",
                $type === 'break_end' && ! $state['onBreak'] => "You're not on a break.",
                default => 'That punch is not allowed right now.',
            });
        }

        $later = $punches->filter(fn (AttendancePunch $punch): bool => $punch->punched_at->getTimestamp() > $at->getTimestamp());

        if ($later->isEmpty()) {
            return;
        }

        $state = $this->replay([new AttendancePunch(['type' => $type])], $state);

        foreach ($later as $punch) {
            if (! in_array($punch->type, $this->allowedFor($state), true)) {
                throw new AttendancePunchException(
                    'That punch would come before one already recorded at '
                    .OrganizationClock::local($punch->punched_at)->format('g:i A').'. Ask for a correction instead.',
                );
            }

            $state = $this->replay([$punch], $state);
        }
    }

    /**
     * Refuse a punch from a source the day's policy does not allow (ADR 0040).
     *
     * @throws AttendancePunchException
     */
    private function assertSourceAllowed(AttendancePolicySettings $policy, string $source): void
    {
        if (! in_array($source, AttendancePunch::CAPTURE_SOURCES, true) || in_array($source, $policy->allowedSources, true)) {
            return;
        }

        $ways = array_map(fn (string $allowed): string => self::SOURCE_WORDS[$allowed] ?? $allowed, $policy->allowedSources);

        throw new AttendancePunchException(
            'Your attendance policy does not allow punching from '.(self::SOURCE_WORDS[$source] ?? $source).'.'
            .($ways !== [] ? ' Punch from '.self::sentence($ways, 'or').' instead.' : ''),
        );
    }

    /**
     * Refuse a punch a phone queued offline that is older than the policy
     * accepts, or stamped ahead of the server's clock (ADR 0040).
     *
     * @throws AttendancePunchException
     */
    private function assertWithinOfflineWindow(AttendancePolicySettings $policy, CarbonImmutable $at, CarbonImmutable $receivedAt): void
    {
        if ($at->lt($receivedAt->subHours($policy->offlineWindowHours))) {
            throw new AttendancePunchException(
                'This punch was made '.OrganizationClock::local($at)->format('M j, g:i A')
                .', more than '.$policy->offlineWindowHours.' hours ago. Ask for a correction instead.',
            );
        }

        if ($at->gt($receivedAt->addMinutes($policy->maxClockSkewMinutes))) {
            throw new AttendancePunchException("This punch is stamped later than now. Check the phone's clock and try again.");
        }
    }

    /**
     * The policy's rules about how a person punches (ADR 0040): a web punch from
     * an allowed address, a phone punch with a selfie.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws AttendancePunchException
     */
    private function assertCaptureRules(AttendancePolicySettings $policy, string $source, array $context): void
    {
        if ($source === 'web' && $policy->webIpAllowlist !== []) {
            $ip = (string) ($context['ip'] ?? '');

            if ($ip === '' || ! IpUtils::checkIp($ip, $policy->webIpAllowlist)) {
                throw new AttendancePunchException('Web punches are only accepted from the office network. Punch from there, or from the mobile app.');
            }
        }

        if ($source === 'mobile' && $policy->selfieRequired && blank($context['photo'] ?? null)) {
            throw new AttendancePunchException('Your attendance policy needs a selfie with every punch from the app. Take one and try again.');
        }
    }

    /**
     * Where a punch was: the nearest work location, the distance to it, and
     * whether it counted as on site ({@see GeofenceCheck}). Only a web or phone
     * punch reports a position; a kiosk's or scanner's is where the device is.
     *
     * The facts are kept whatever the policy's mode, so a reviewer can always
     * see where somebody punched. The mode decides what they mean:
     *
     *  - `off` — nothing is flagged or refused.
     *  - `flag` — a punch not shown to be on site (outside, or with no position
     *    at all) is accepted, and the evaluator flags the day.
     *  - `block` — such a punch from a person is refused, naming the nearest site
     *    and how far off it was. A day of approved remote work or official
     *    business is exempt.
     *
     * A company with no locations has nothing to check against, so nothing is.
     *
     * @param  array<string, mixed>  $context
     * @return array{work_location_id: ?int, distance_meters: ?int, within_geofence: ?bool}
     *
     * @throws AttendancePunchException
     */
    private function place(Employee $employee, string $date, AttendancePolicySettings $policy, string $source, array $context, bool $enforce): array
    {
        $unplaced = ['work_location_id' => null, 'distance_meters' => null, 'within_geofence' => null];

        if (! in_array($source, AttendancePunch::LOCATED_SOURCES, true)) {
            return [...$unplaced, 'work_location_id' => isset($context['work_location_id']) ? (int) $context['work_location_id'] : null];
        }

        $locations = WorkLocation::fenceFor($employee);

        if ($locations->isEmpty()) {
            return $unplaced;
        }

        $latitude = $context['latitude'] ?? null;
        $longitude = $context['longitude'] ?? null;

        $verdict = is_numeric($latitude) && is_numeric($longitude)
            ? GeofenceCheck::check((float) $latitude, (float) $longitude, is_numeric($context['accuracy'] ?? null) ? (float) $context['accuracy'] : null, $locations)
            : null;

        $placement = $verdict !== null
            ? ['work_location_id' => $verdict->location->id, 'distance_meters' => $verdict->distanceMeters, 'within_geofence' => $verdict->inside]
            // No position while the policy checks one: not shown to be on site.
            : [...$unplaced, 'within_geofence' => $policy->geofence === 'off' ? null : false];

        if (! $enforce || $policy->geofence !== 'block' || $placement['within_geofence'] !== false || $this->awayFromSiteExcused($employee, $date)) {
            return $placement;
        }

        throw new AttendancePunchException($verdict === null
            ? 'Your attendance policy needs to know you are on site. Turn on location and try again.'
            : 'You are '.self::distance($verdict->distanceMeters).' from '.$verdict->location->name.'. Punches have to be made on site.');
    }

    /**
     * Whether an approved request says the employee is meant to be away from the
     * site that day — remote work or official business.
     */
    private function awayFromSiteExcused(Employee $employee, string $date): bool
    {
        return AttendanceRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereIn('type', ['remote_work', 'official_business'])
            ->overlapping($date, $date)
            ->exists();
    }

    /**
     * The punch already received under a sender's id, if there is one — a
     * replaced one included, so a device resending a punch HR has since fixed
     * does not bring it back.
     */
    private function alreadyReceived(Employee $employee, ?int $deviceId, string $externalId): ?CapturedPunch
    {
        $punch = AttendancePunch::withTrashed()
            ->where('external_id', $externalId)
            ->when(
                $deviceId !== null,
                fn ($query) => $query->where('attendance_device_id', $deviceId),
                fn ($query) => $query->whereNull('attendance_device_id')->where('employee_id', $employee->id),
            )
            ->first();

        $record = $punch?->record()->first();

        return $punch !== null && $record !== null ? new CapturedPunch($record, $punch, duplicate: true) : null;
    }

    /**
     * "the web, a kiosk or the mobile app".
     *
     * @param  list<string>  $items
     */
    private static function sentence(array $items, string $last): string
    {
        return count($items) <= 1
            ? implode('', $items)
            : implode(', ', array_slice($items, 0, -1))." {$last} ".end($items);
    }

    /** "80 m", "1.2 km". */
    private static function distance(int $meters): string
    {
        return $meters < 1000 ? "{$meters} m" : number_format($meters / 1000, 1).' km';
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

    /**
     * The request-scoped lock guard (ADR 0039).
     */
    private function lock(): PeriodLock
    {
        return app(PeriodLock::class);
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
