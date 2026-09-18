<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Attendance\StoreAttendanceRequestRequest;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Queries\AttendanceRangeQuery;
use App\Services\Assistant\Contracts\ContributesContext;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Retrieval\RetrievedSubject;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendanceException;
use App\Support\Attendance\AttendancePunchException;
use App\Support\Attendance\AttendanceRequestApprover;
use App\Support\Attendance\AttendanceRequestFiler;
use App\Support\Attendance\ResolvedShift;
use App\Support\Attendance\RosterWriter;
use App\Support\Attendance\ShiftResolver;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Attendance capability: look up an employee's Daily Time Records, record a
 * clock punch on their behalf, read the shift roster and override one day of it,
 * and file, find and decide attendance requests (ADR 0039).
 *
 * Every punch goes through {@see AttendanceClock}, every override through
 * {@see RosterWriter}, every request through {@see AttendanceRequestFiler} and
 * every decision through {@see AttendanceRequestApprover} — the same engine and
 * writers the web and mobile API use — so totals, status, history and the period
 * lock hold whoever asked for the change.
 */
class AttendanceModule extends Module implements ContributesContext
{
    public function __construct(
        private readonly AttendanceClock $clock,
        private readonly ShiftResolver $shifts,
        private readonly RosterWriter $roster,
        private readonly AttendanceRequestFiler $filer,
        private readonly AttendanceRequestApprover $approver,
    ) {}

    public function key(): string
    {
        return 'attendance';
    }

    /**
     * Anybody who reads attendance, or files or reviews requests about it — a
     * member of staff can ask for a correction without seeing the whole board.
     */
    public function isAvailable(User $user): bool
    {
        return $user->can('attendance.view')
            || $user->can('attendance.request')
            || $user->can('attendance.requests.review');
    }

    protected function toolMap(): array
    {
        return [
            'find_attendance' => 'findAttendance',
            'record_punch' => 'recordPunch',
            'find_shifts' => 'findShifts',
            'set_roster_entry' => 'setRosterEntry',
            'file_attendance_request' => 'fileRequest',
            'find_attendance_requests' => 'findRequests',
            'review_attendance_request' => 'reviewRequest',
        ];
    }

    /**
     * `find_attendance`, `find_shifts` and `find_attendance_requests` answer for
     * yourself without a permission and check anybody else inside.
     */
    protected function permissionMap(): array
    {
        return [
            'record_punch' => 'attendance.manage',
            'set_roster_entry' => 'attendance.roster.manage',
            'file_attendance_request' => 'attendance.request',
            'review_attendance_request' => 'attendance.requests.review',
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    /** How far back an attendance read-out looks when nobody says otherwise. */
    private const CONTEXT_DAYS = 30;

    /** Statuses that mean the employee showed up. */
    private const PRESENT_STATUSES = AttendanceRecord::PRESENT_STATUSES;

    /** How many pending requests the brief lists. */
    private const CONTEXT_REQUESTS = 5;

    /** How many requests one read-out returns. */
    private const MAX_REQUEST_CARDS = 20;

    /** The widest range one roster read-out covers. */
    private const MAX_ROSTER_DAYS = 31;

    /** How many people an un-narrowed roster read-out looks at. */
    private const MAX_ROSTER_PEOPLE = 200;

    /** How many shifts one read-out returns, so a whole month cannot flood the reply. */
    private const MAX_ROSTER_CARDS = 40;

    /**
     * Why a shift applies, said the way somebody would say it.
     *
     * @var array<string, string>
     */
    private const SOURCE_LABELS = [
        'roster' => 'One-off override',
        'assignment' => 'Assigned shift',
        'employee' => 'Assigned shift',
        'department' => 'Department default',
        'organization' => 'Company default',
        'fallback' => 'Default hours',
    ];

    /**
     * How this person has actually been turning up — the closest thing the
     * assistant has to an answer for "how are they doing?".
     *
     * It is built from the same day-matrix the weekly grid and the monthly
     * report are built from ({@see AttendanceRangeQuery}), so a day with no
     * record still counts as the absence or the rest day it was, rather than
     * quietly not existing. A read-out from saved punches alone would flatter
     * everybody who never clocked in at all.
     *
     * Their own DTR is readable without `attendance.view`, because
     * `/attendance/me` is.
     */
    public function contextFor(User $user, RetrievedSubject $subject): ?ContextSection
    {
        $employee = $subject->employeeModel();

        if ($employee === null || (! $subject->isSelf && $user->cannot('attendance.view'))) {
            return null;
        }

        $end = CarbonImmutable::parse(OrganizationClock::today());
        $start = $end->subDays(self::CONTEXT_DAYS - 1);

        $row = app(AttendanceRangeQuery::class)
            ->days($start->toDateString(), $end->toDateString(), null, (string) $employee->employee_no)
            ->first(fn (array $row): bool => $row['employee']->id === $employee->id);

        $cells = array_values(array_filter(
            $row['cells'] ?? [],
            fn (array $cell): bool => ! $cell['is_future'] && $cell['status'] !== null,
        ));

        $pending = AttendanceRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'pending')
            ->orderBy('start_date')
            ->limit(self::CONTEXT_REQUESTS)
            ->get();

        if ($cells === [] && $pending->isEmpty()) {
            return null;
        }

        $counts = [];
        $flags = [];
        $lateMinutes = 0;
        $overtimeMinutes = 0;
        $approvedOvertime = 0;
        $nightMinutes = 0;
        $restDayMinutes = 0;
        $holidayMinutes = 0;
        $workedMinutes = 0;
        $worked = 0;

        foreach ($cells as $cell) {
            $counts[$cell['status']] = ($counts[$cell['status']] ?? 0) + 1;
            $lateMinutes += (int) $cell['late_minutes'];
            $overtimeMinutes += (int) $cell['overtime_minutes'];
            $approvedOvertime += (int) $cell['approved_overtime_minutes'];
            $nightMinutes += (int) $cell['night_minutes'];
            $restDayMinutes += (int) $cell['rest_day_minutes'];
            $holidayMinutes += (int) $cell['holiday_minutes'];

            foreach ($cell['flags'] as $flag) {
                $flags[$flag] = ($flags[$flag] ?? 0) + 1;
            }

            if (in_array($cell['status'], self::PRESENT_STATUSES, true)) {
                $worked++;
                $workedMinutes += (int) $cell['worked_minutes'];
            }
        }

        $scheduled = $worked + ($counts['absent'] ?? 0);
        $late = $counts['late'] ?? 0;

        $recent = array_slice(array_reverse($cells), 0, 5);

        return ContextSection::of('Attendance (last '.self::CONTEXT_DAYS.' days)', [
            'Window: '.$start->toDateString().' to '.$end->toDateString().', '.count($cells).' days accounted for',
            'Worked '.$worked.' of '.$scheduled.' scheduled days'.($scheduled > 0 ? ' ('.round($worked / $scheduled * 100).'% attendance)' : ''),
            'Late on '.$late.' of those days'.($worked > 0 ? ' ('.round(($worked - $late) / $worked * 100).'% on time)' : '').
                ($lateMinutes > 0 ? ', '.$this->hours($lateMinutes).' late in total' : ''),
            ($counts['absent'] ?? 0) > 0 ? 'Absent '.$counts['absent'].' day'.($counts['absent'] === 1 ? '' : 's') : 'No unexplained absences',
            ($counts['half_day'] ?? 0) > 0 ? 'Judged a half day on '.$counts['half_day'].' day'.($counts['half_day'] === 1 ? '' : 's').' (very late or very short, by the company policy)' : null,
            ($counts['on_leave'] ?? 0) > 0 ? 'On approved leave '.$counts['on_leave'].' day'.($counts['on_leave'] === 1 ? '' : 's') : null,
            ($counts['holiday'] ?? 0) > 0 ? 'Public holidays (not scheduled) '.$counts['holiday'].' day'.($counts['holiday'] === 1 ? '' : 's') : null,
            ($counts['incomplete'] ?? 0) > 0 ? 'Missing a clock-out on '.$counts['incomplete'].' day'.($counts['incomplete'] === 1 ? '' : 's') : null,
            $worked > 0 ? 'Averaging '.$this->hours((int) round($workedMinutes / $worked)).' worked per day' : null,
            $overtimeMinutes > 0 ? $this->hours($overtimeMinutes).' of overtime'.(
                $overtimeMinutes > $approvedOvertime ? ', '.$this->hours($overtimeMinutes - $approvedOvertime).' of it not approved'.(
                    ($flags['unapproved_overtime'] ?? 0) > 0 ? ' ('.$flags['unapproved_overtime'].' day'.($flags['unapproved_overtime'] === 1 ? '' : 's').' still awaiting a decision)' : ''
                ) : ''
            ) : null,
            $nightMinutes > 0 ? $this->hours($nightMinutes).' worked in the night-differential window' : null,
            $restDayMinutes > 0 ? $this->hours($restDayMinutes).' worked on rest days' : null,
            $holidayMinutes > 0 ? $this->hours($holidayMinutes).' worked on holidays' : null,
            ($flags['break_exceeded'] ?? 0) > 0 ? 'Took a longer break than the policy allows on '.$flags['break_exceeded'].' day'.($flags['break_exceeded'] === 1 ? '' : 's') : null,
            $recent === [] ? null : 'Most recent days — '.implode('; ', array_map(
                fn (array $cell): string => $cell['date'].': '.str_replace('_', ' ', (string) $cell['status']).
                    ((int) $cell['late_minutes'] > 0 ? ' ('.$this->hours((int) $cell['late_minutes']).' late)' : ''),
                $recent,
            )),
            ($flags['official_business'] ?? 0) > 0 ? 'On approved official business '.$flags['official_business'].' day'.($flags['official_business'] === 1 ? '' : 's') : null,
            $pending->isEmpty() ? null : 'Attendance requests awaiting a decision — '.implode('; ', $pending->map(
                fn (AttendanceRequest $request): string => AttendanceRequestFiler::label($request).' (filed '.$request->created_at?->diffForHumans().')',
            )->all()),
        ]);
    }

    /**
     * Minutes as the hours and minutes a person would say out loud.
     */
    private function hours(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.'m';
        }

        $rest = $minutes % 60;

        return intdiv($minutes, 60).'h'.($rest > 0 ? ' '.$rest.'m' : '');
    }

    public function guidance(User $user): string
    {
        return <<<'TXT'
        ATTENDANCE — Daily Time Records (DTR): one record per employee per day, built from clock in/out and break punches. Worked hours, lateness, undertime and overtime are computed server-side against the employee's work schedule and the company's attendance policy (grace, rounding, overtime rules, night differential). Worked minutes split into regular and overtime; night, rest-day and holiday minutes are tags over those same minutes. Overtime under a policy that requires approval is "awaiting approval" until approved. A day can be a "half day" when it was very late or very short by the policy's thresholds.
        - find_attendance lists an employee's recent records (pass `date` as YYYY-MM-DD for one specific day).
        - record_punch logs a clock punch for an employee: type is clock_in, clock_out, break_start or break_end. Punch order is validated (you can't clock out before clocking in). Punches are timed on the organisation's clock and filed under the shift they belong to — a night shift's clock-out after midnight closes the previous evening's day.
        - find_shifts answers "who works Saturday?" and "what is Ana's shift next week?" — it reads the roster (the plan), not the records (what happened). Pass `date` for one day, or `from` and `to` for a range; pass `employee` to narrow it to one person. Each shift says where it came from: a one-off roster override, a dated assignment, a department or company default, or the built-in Mon–Fri fallback.
        - set_roster_entry puts one person on different hours for one date — a swap, a Saturday call-in, or a day off. Either name a `schedule` to borrow for that day, or give `start` and `end` times, or set `rest_day` to true. It overwrites any existing override for that person and date.
        - file_attendance_request asks for something the clock could not capture: a `correction` (the day's punches as they should read — give only the times that change, e.g. "I forgot to clock out yesterday, I left at 6" is time_out 18:00 for yesterday), `overtime` (minutes or hours; a future date is a pre-approval), `official_business` (a client visit or field work — the day counts as a full working day) or `remote_work`. A reason is required: ask for one if the user gave none. Leave `employee` out to file for the user themselves.
        - find_attendance_requests lists requests (pending by default). Without review rights it only shows the user's own.
        - review_attendance_request approves or rejects a pending request. Nobody reviews their own, and a day in a locked attendance period cannot be decided.
        - Pass `employee` as a name or employee number.
        TXT;
    }

    public function tools(User $user): array
    {
        return $this->permitted($user, [
            [
                'name' => 'find_attendance',
                'description' => "List an employee's daily time records, most recent first.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or employee number.'],
                        'date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD to fetch a single day (optional).'],
                    ],
                    'required' => ['employee'],
                ],
            ],
            [
                'name' => 'record_punch',
                'description' => 'Record a clock punch (in/out or break) for an employee.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or employee number.'],
                        'type' => ['type' => 'STRING', 'enum' => ['clock_in', 'clock_out', 'break_start', 'break_end']],
                    ],
                    'required' => ['employee', 'type'],
                ],
            ],
            [
                'name' => 'find_shifts',
                'description' => 'Read the shift roster: who is due to work what, on a date or across a range.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or number, to narrow it to one person (optional).'],
                        'date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD for a single day.'],
                        'from' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD, the first day of a range.'],
                        'to' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD, the last day of a range.'],
                        'working_only' => ['type' => 'BOOLEAN', 'description' => 'Leave out rest days (default true).'],
                    ],
                ],
            ],
            [
                'name' => 'set_roster_entry',
                'description' => "Override one employee's shift for one date.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or employee number.'],
                        'date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD, the date to override.'],
                        'schedule' => ['type' => 'STRING', 'description' => "A work schedule's name, to borrow its hours for that date."],
                        'start' => ['type' => 'STRING', 'description' => 'HH:MM start time, when giving the day its own hours.'],
                        'end' => ['type' => 'STRING', 'description' => 'HH:MM end time.'],
                        'rest_day' => ['type' => 'BOOLEAN', 'description' => 'Make it a day off instead.'],
                        'reason' => ['type' => 'STRING', 'description' => 'Why (optional).'],
                    ],
                    'required' => ['employee', 'date'],
                ],
            ],
            [
                'name' => 'file_attendance_request',
                'description' => 'File an attendance request: a punch correction, overtime, official business or remote work.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'type' => ['type' => 'STRING', 'enum' => AttendanceRequest::TYPES],
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or number. Leave out to file for yourself.'],
                        'date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD — the day, or the first day of a range.'],
                        'end_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD, the last day (official business / remote work).'],
                        'time_in' => ['type' => 'STRING', 'description' => 'HH:MM, correction: the clock-in the day should show.'],
                        'break_start' => ['type' => 'STRING', 'description' => 'HH:MM, correction.'],
                        'break_end' => ['type' => 'STRING', 'description' => 'HH:MM, correction.'],
                        'time_out' => ['type' => 'STRING', 'description' => 'HH:MM, correction: the clock-out the day should show (24-hour).'],
                        'minutes' => ['type' => 'INTEGER', 'description' => 'Overtime asked for, in minutes.'],
                        'hours' => ['type' => 'NUMBER', 'description' => 'Overtime asked for, in hours (instead of minutes).'],
                        'start_time' => ['type' => 'STRING', 'description' => 'HH:MM, official business / remote work (optional).'],
                        'end_time' => ['type' => 'STRING', 'description' => 'HH:MM (optional).'],
                        'location' => ['type' => 'STRING', 'description' => 'Where, for official business (optional).'],
                        'reason' => ['type' => 'STRING', 'description' => 'Why — required.'],
                    ],
                    'required' => ['type', 'date', 'reason'],
                ],
            ],
            [
                'name' => 'find_attendance_requests',
                'description' => 'List attendance requests — pending by default.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or number (optional).'],
                        'status' => ['type' => 'STRING', 'enum' => [...AttendanceRequest::STATUSES, 'all']],
                        'type' => ['type' => 'STRING', 'enum' => AttendanceRequest::TYPES],
                    ],
                ],
            ],
            [
                'name' => 'review_attendance_request',
                'description' => "Approve or reject an employee's pending attendance request.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or number.'],
                        'action' => ['type' => 'STRING', 'enum' => ['approve', 'reject']],
                        'type' => ['type' => 'STRING', 'enum' => AttendanceRequest::TYPES, 'description' => 'Which request, when they have more than one pending.'],
                        'date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD, which day, when they have more than one pending.'],
                        'review_note' => ['type' => 'STRING', 'description' => 'A note the employee sees (optional).'],
                    ],
                    'required' => ['employee', 'action'],
                ],
            ],
        ]);
    }

    // ── Tools ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findAttendance(User $user, array $args): ToolResult
    {
        $employee = $this->locateEmployee($args);

        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        if ($employee->id !== $user->employee?->id && $user->cannot('attendance.view')) {
            return $this->denied("view other people's attendance");
        }

        $date = $this->date($args['date'] ?? null);

        $records = AttendanceRecord::query()
            ->with('employee')
            ->where('employee_id', $employee->id)
            ->when($date, fn ($q) => $q->whereDate('work_date', $date))
            ->orderByDesc('work_date')
            ->limit($date ? 1 : 7)
            ->get();

        $cards = $records->map(fn (AttendanceRecord $r): array => $this->recordCard($r, 'find', 'neutral'))->all();

        return ToolResult::found("Attendance for {$employee->full_name}", count($cards).' day'.(count($cards) === 1 ? '' : 's'), $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function recordPunch(User $user, array $args): ToolResult
    {
        if ($user->cannot('attendance.manage')) {
            return $this->denied('record attendance punches');
        }

        $employee = $this->locateEmployee($args);

        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        $type = strtolower(trim((string) ($args['type'] ?? '')));

        try {
            $record = $this->clock->punch($employee, $type, ['source' => 'manual', 'recorded_by' => $user->id]);
        } catch (AttendancePunchException $e) {
            return ToolResult::error('Recorded the punch', $e->getMessage());
        }

        ActivityLogger::log(
            event: 'updated',
            description: "Recorded {$type} for {$employee->full_name} via assistant",
            subject: $record,
            logName: 'attendance',
            subjectLabel: $employee->full_name,
        );

        $record->setRelation('employee', $employee);

        return ToolResult::ok(
            $this->label($type)." for {$employee->full_name}",
            $record->status,
            $this->recordCard($record, 'add', 'positive'),
        );
    }

    /**
     * Who is due to work what. Reads the roster through {@see ShiftResolver}, so
     * the answer is the same one the board shows — including *why* a shift
     * applies, which is usually the real question behind "why is Ben on nights?".
     *
     * Capped at {@see MAX_ROSTER_DAYS} days and {@see MAX_ROSTER_CARDS} cards so a
     * careless "show me the roster" cannot return the whole quarter.
     *
     * @param  array<string, mixed>  $args
     */
    private function findShifts(User $user, array $args): ToolResult
    {
        $employee = $this->locateEmployee($args);
        $isSelf = $employee !== null && $employee->id === $user->employee?->id;

        if (! $isSelf && $user->cannot('attendance.roster.view')) {
            return $this->denied('view the shift roster');
        }

        $from = $this->date($args['from'] ?? $args['date'] ?? null) ?? OrganizationClock::today();
        $to = $this->date($args['to'] ?? $args['date'] ?? null) ?? $from;

        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        $to = min($to, CarbonImmutable::parse($from)->addDays(self::MAX_ROSTER_DAYS - 1)->toDateString());

        $employees = $employee !== null
            ? collect([$employee])
            : Employee::query()->orderBy('first_name')->orderBy('last_name')->limit(self::MAX_ROSTER_PEOPLE)->get();

        if ($employees->isEmpty()) {
            return ToolResult::error('Read the roster', 'No matching employee found.');
        }

        $shifts = $this->shifts->forMany($employees, $from, $to);
        $workingOnly = ! array_key_exists('working_only', $args) || (bool) $args['working_only'];

        $cards = [];

        foreach ($employees as $person) {
            foreach ($shifts[$person->id] ?? [] as $shift) {
                if ($workingOnly && ! $shift->isWorkingDay) {
                    continue;
                }

                $cards[] = $this->shiftCard($person, $shift);

                if (count($cards) >= self::MAX_ROSTER_CARDS) {
                    break 2;
                }
            }
        }

        $period = $from === $to
            ? CarbonImmutable::parse($from)->format('D, M j')
            : CarbonImmutable::parse($from)->format('M j').' – '.CarbonImmutable::parse($to)->format('M j');

        if ($cards === []) {
            return ToolResult::found("Roster for {$period}", $workingOnly ? 'Nobody is scheduled' : 'Nothing rostered', []);
        }

        return ToolResult::found(
            ($employee !== null ? "{$employee->full_name}'s shifts" : 'Roster')." for {$period}",
            count($cards).' shift'.(count($cards) === 1 ? '' : 's'),
            $cards,
        );
    }

    /**
     * Put somebody on different hours for one date, through the same writer the
     * roster board uses.
     *
     * @param  array<string, mixed>  $args
     */
    private function setRosterEntry(User $user, array $args): ToolResult
    {
        if ($user->cannot('attendance.roster.manage')) {
            return $this->denied('change the shift roster');
        }

        $employee = $this->locateEmployee($args);

        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        $date = $this->date($args['date'] ?? null);

        if ($date === null) {
            return ToolResult::error('Set the shift', 'Give the date to override, as YYYY-MM-DD.');
        }

        $isRestDay = (bool) ($args['rest_day'] ?? false);
        $schedule = $isRestDay ? null : $this->locateSchedule($args['schedule'] ?? null);
        $segments = $isRestDay ? null : $this->segment($args['start'] ?? null, $args['end'] ?? null);

        if (! $isRestDay && $schedule === null && $segments === null) {
            return ToolResult::error('Set the shift', 'Name a schedule to borrow, give start and end times, or make it a rest day.');
        }

        $entry = $this->roster->set($employee, $date, [
            'work_schedule_id' => $schedule?->id,
            'segments' => $segments,
            'is_rest_day' => $isRestDay,
            'reason' => $args['reason'] ?? null,
        ], $user->id);

        ActivityLogger::log(
            event: 'updated',
            description: "Rostered {$employee->full_name} for ".($isRestDay ? 'a rest day' : 'a different shift')
                .' on '.CarbonImmutable::parse($date)->format('M j').' via assistant',
            subject: $entry,
            properties: ['date' => $date, 'reason' => $entry->reason],
            logName: 'attendance',
            subjectLabel: $employee->full_name,
        );

        $shift = $this->shifts->for($employee->refresh(), $date);

        return ToolResult::ok(
            "{$employee->full_name} on ".CarbonImmutable::parse($date)->format('D, M j'),
            $shift->label(),
            $this->shiftCard($employee, $shift, 'add', 'positive'),
        );
    }

    /**
     * File a request through the same validation and filer the web uses.
     *
     * @param  array<string, mixed>  $args
     */
    private function fileRequest(User $user, array $args): ToolResult
    {
        if ($user->cannot('attendance.request')) {
            return $this->denied('file attendance requests');
        }

        $employee = filled($args['employee'] ?? null) ? $this->locateEmployee($args) : $user->employee;

        if (! $employee) {
            return ToolResult::error('Looked up the employee', filled($args['employee'] ?? null)
                ? 'No matching employee found.'
                : 'Your account is not linked to an employee record.');
        }

        if ($employee->id !== $user->employee?->id && $user->cannot('attendance.manage')) {
            return $this->denied("file requests on somebody else's behalf");
        }

        $type = strtolower(trim((string) ($args['type'] ?? '')));
        $minutes = isset($args['minutes']) ? (int) $args['minutes'] : (isset($args['hours']) ? (int) round((float) $args['hours'] * 60) : null);

        $data = array_filter([
            'type' => $type,
            'employee_id' => $employee->id,
            'start_date' => $this->date($args['date'] ?? $args['start_date'] ?? null),
            'end_date' => $this->date($args['end_date'] ?? null),
            'reason' => $args['reason'] ?? null,
            'time_in' => $this->clockTime($args['time_in'] ?? null),
            'break_start' => $this->clockTime($args['break_start'] ?? null),
            'break_end' => $this->clockTime($args['break_end'] ?? null),
            'time_out' => $this->clockTime($args['time_out'] ?? null),
            'minutes' => $minutes,
            'start_time' => $this->clockTime($args['start_time'] ?? null),
            'end_time' => $this->clockTime($args['end_time'] ?? null),
            'location' => $args['location'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '');

        $validator = Validator::make($data, StoreAttendanceRequestRequest::rulesFor($type), StoreAttendanceRequestRequest::messagesFor());
        $validator->after(fn ($validator) => StoreAttendanceRequestRequest::checks($validator));

        if ($validator->fails()) {
            return ToolResult::error('Validated the request', $validator->errors()->first());
        }

        try {
            $request = $this->filer->file($employee, $validator->validated(), $user, via: 'assistant');
        } catch (AttendanceException $e) {
            return ToolResult::error('Filed the request', $e->getMessage());
        }

        return ToolResult::ok(
            'Filed '.AttendanceRequestFiler::label($request)." for {$employee->full_name}",
            'Awaiting review',
            $this->requestCard($request, 'add', 'info'),
        );
    }

    /**
     * List requests. A reviewer sees anybody's; everybody else only their own.
     *
     * @param  array<string, mixed>  $args
     */
    private function findRequests(User $user, array $args): ToolResult
    {
        $reviewer = $user->can('attendance.requests.review');
        $employee = filled($args['employee'] ?? null) ? $this->locateEmployee($args) : null;

        if (filled($args['employee'] ?? null) && $employee === null) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        if (! $reviewer) {
            if ($user->employee === null || ($employee !== null && $employee->id !== $user->employee->id)) {
                return $this->denied("see other people's attendance requests");
            }

            $employee = $user->employee;
        }

        $status = strtolower(trim((string) ($args['status'] ?? 'pending')));
        $type = strtolower(trim((string) ($args['type'] ?? '')));

        $requests = AttendanceRequest::query()
            ->with('employee')
            ->when($employee !== null, fn ($query) => $query->where('employee_id', $employee->id))
            ->when(in_array($status, AttendanceRequest::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when(in_array($type, AttendanceRequest::TYPES, true), fn ($query) => $query->where('type', $type))
            ->orderByDesc('created_at')
            ->limit(self::MAX_REQUEST_CARDS)
            ->get();

        $cards = $requests->map(fn (AttendanceRequest $request): array => $this->requestCard($request, 'find', 'neutral'))->all();

        return ToolResult::found(
            ($employee !== null ? "{$employee->full_name}'s" : 'Attendance').' requests',
            count($cards).' found',
            $cards,
        );
    }

    /**
     * Decide a pending request through the approver the inbox uses.
     *
     * @param  array<string, mixed>  $args
     */
    private function reviewRequest(User $user, array $args): ToolResult
    {
        if ($user->cannot('attendance.requests.review')) {
            return $this->denied('approve or reject attendance requests');
        }

        $action = strtolower(trim((string) ($args['action'] ?? '')));

        if (! in_array($action, ['approve', 'reject'], true)) {
            return ToolResult::error('Reviewed the request', 'Action must be approve or reject.');
        }

        $employee = $this->locateEmployee($args);

        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        $type = strtolower(trim((string) ($args['type'] ?? '')));
        $date = $this->date($args['date'] ?? null);

        $pending = AttendanceRequest::query()
            ->where('employee_id', $employee->id)
            ->where('status', 'pending')
            ->when(in_array($type, AttendanceRequest::TYPES, true), fn ($query) => $query->where('type', $type))
            ->when($date !== null, fn ($query) => $query->overlapping($date, $date))
            ->orderBy('created_at')
            ->get();

        if ($pending->isEmpty()) {
            return ToolResult::error('Looked up the request', "{$employee->full_name} has no pending request like that.");
        }

        if ($pending->count() > 1) {
            return ToolResult::error('Looked up the request', "{$employee->full_name} has {$pending->count()} pending requests — say which type or date.");
        }

        try {
            $request = $action === 'approve'
                ? $this->approver->approve($pending->first(), $user, $args['review_note'] ?? null)
                : $this->approver->reject($pending->first(), $user, $args['review_note'] ?? null);
        } catch (AttendanceException $e) {
            return ToolResult::error('Reviewed the request', $e->getMessage());
        }

        $approved = $action === 'approve';

        return ToolResult::ok(
            ($approved ? 'Approved ' : 'Rejected ').AttendanceRequestFiler::label($request)." for {$employee->full_name}",
            null,
            $this->requestCard($request, $approved ? 'approve' : 'reject', $approved ? 'positive' : 'danger'),
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * One request as a card.
     *
     * @return array<string, mixed>
     */
    private function requestCard(AttendanceRequest $request, string $kind, string $tone): array
    {
        $employee = $request->employee;

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: ucfirst($request->status),
            title: $employee?->full_name ?? 'Employee',
            subtitle: ucfirst(AttendanceRequestFiler::label($request)),
            meta: [$request->reason],
            avatar: $employee
                ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
                : null,
            id: $request->id,
        );
    }

    /**
     * A clock-face time the model gave ("18:00", "6:00 PM", "18:00:00") as "HH:MM",
     * or null.
     */
    private function clockTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('H:i');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function locateEmployee(array $args): ?Employee
    {
        $needle = $this->firstFilled($args, ['employee', 'match', 'employee_name', 'name']);

        return $needle ? $this->matchByTokens(Employee::query(), $needle)->first() : null;
    }

    /**
     * The schedule the model named. An exact (case-insensitive) name first, so
     * "Day Shift" never lands on "Day Shift (Manila)"; failing that, the closest
     * name containing what was asked for.
     */
    private function locateSchedule(mixed $name): ?WorkSchedule
    {
        $needle = trim((string) $name);

        if ($needle === '') {
            return null;
        }

        $id = $this->resolveId(WorkSchedule::query(), 'name', $needle);

        if ($id !== null) {
            return WorkSchedule::find($id);
        }

        $query = WorkSchedule::query();
        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where('name', $like, '%'.$needle.'%')->orderBy('name')->first();
    }

    /**
     * A start and end the model gave as one segment, or null when it gave neither.
     *
     * @return list<array{start: string, end: string}>|null
     */
    private function segment(mixed $start, mixed $end): ?array
    {
        $start = trim((string) $start);
        $end = trim((string) $end);

        if ($start === '' || $end === '') {
            return null;
        }

        return [['start' => substr($start, 0, 5), 'end' => substr($end, 0, 5)]];
    }

    /**
     * One rostered day as a card: whose it is, when, and why it applies.
     *
     * @return array<string, mixed>
     */
    private function shiftCard(Employee $employee, ResolvedShift $shift, string $kind = 'find', string $tone = 'neutral'): array
    {
        return $this->card(
            kind: $kind,
            tone: $shift->isWorkingDay ? $tone : 'neutral',
            badge: $shift->isWorkingDay ? $shift->label() : 'Rest day',
            title: $employee->full_name,
            subtitle: CarbonImmutable::parse($shift->date)->format('D, M j').' · '.($shift->scheduleName ?? 'Default hours'),
            meta: [
                self::SOURCE_LABELS[$shift->source] ?? $shift->source,
                $shift->isWorkingDay ? $this->hours($shift->requiredMinutes) : '',
            ],
            avatar: ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url],
            id: $employee->id,
        );
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function label(string $type): string
    {
        return match ($type) {
            'clock_in' => 'Clocked in',
            'clock_out' => 'Clocked out',
            'break_start' => 'Started break',
            'break_end' => 'Ended break',
            default => 'Punched',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function recordCard(AttendanceRecord $record, string $kind, string $tone): array
    {
        $employee = $record->employee;
        $in = $record->first_in_at ? OrganizationClock::local($record->first_in_at)->format('g:i A') : '—';
        $out = $record->last_out_at ? OrganizationClock::local($record->last_out_at)->format('g:i A') : '—';
        $hours = round($record->worked_minutes / 60, 1);

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: ucfirst(str_replace('_', ' ', $record->status)),
            title: $employee?->full_name ?? 'Employee',
            subtitle: $record->work_date->format('D, M j')." · {$in} – {$out}",
            meta: ["{$hours} h", $record->late_minutes > 0 ? "{$record->late_minutes}m late" : '', $record->overtime_minutes > 0 ? "{$record->overtime_minutes}m OT" : ''],
            avatar: $employee
                ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
                : null,
            id: $record->id,
        );
    }
}
