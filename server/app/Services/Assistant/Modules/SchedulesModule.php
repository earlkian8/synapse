<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Setup\HolidayRequest;
use App\Http\Requests\Setup\WorkScheduleRequest;
use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\WorkScheduleDay;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\Attendance\AttendanceCoverage;
use App\Support\Attendance\PolicyDescription;
use App\Support\OrganizationClock;
use App\Support\Setup\HolidayWorkflow;
use App\Support\Setup\WorkScheduleWorkflow;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Work Schedule & Holidays capability: the shift templates people are assigned
 * to (ADR 0037) and the holiday calendar.
 *
 * **Reading** answers "when is the next holiday?", "what are the holidays in
 * 2027?", "what are the Night Shift's hours?", "which schedule is the company
 * default?". **Doing** manages the calendar and the templates through
 * {@see HolidayWorkflow} and {@see WorkScheduleWorkflow} — the screen's own
 * paths — against the screen's own rules ({@see HolidayRequest},
 * {@see WorkScheduleRequest} and its pattern checks).
 *
 * A schedule made or reshaped here is a plain week with the same hours on each
 * working day: that is what a sentence can say without ambiguity. Rotations,
 * split shifts, different hours on different days and a day's earliest start /
 * latest end are set on the screen, and a schedule that already has any of them
 * is never flattened by a chat edit — its hours are refused, not rewritten.
 * Who works which template on which date is the roster, in the Attendance
 * capability.
 *
 * Consequential changes wait for the user's Confirm (ADR 0049), and the card
 * says who they reach ({@see AttendanceCoverage}): editing, archiving or making
 * a schedule the default, and archiving a holiday. Permanent deletion stays on
 * the screen. Everything needs `setup.schedule.view`; changes
 * `setup.schedule.manage`.
 */
class SchedulesModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    /** How many holidays or schedules a list returns. */
    private const MAX_RESULTS = 40;

    /** The widest date range one holiday list covers. */
    private const MAX_RANGE_DAYS = 731;

    /** How many day lines a schedule read-out spells out. */
    private const MAX_DAY_LINES = 14;

    /** @var list<string> */
    private const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * The holiday types as the screen names them.
     *
     * @var array<string, string>
     */
    private const HOLIDAY_TYPES = [
        'regular' => 'Regular holiday',
        'special_non_working' => 'Special (non-working)',
        'special_working' => 'Special (working)',
    ];

    /**
     * The schedule types as the screen names them.
     *
     * @var array<string, string>
     */
    private const SCHEDULE_TYPES = [
        'fixed' => 'Fixed hours',
        'flexible' => 'Flexible hours',
        'hours_only' => 'Hours only',
    ];

    /** The tool arguments that reshape a schedule's day pattern. */
    private const PATTERN_ARGS = ['type', 'work_days', 'start', 'end', 'required_minutes', 'unpaid_break_minutes', 'core_start', 'core_end'];

    public function __construct(
        private readonly HolidayWorkflow $holidays,
        private readonly WorkScheduleWorkflow $schedules,
    ) {}

    public function key(): string
    {
        return 'schedules';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('setup.schedule.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_holidays' => 'findHolidays',
            'find_work_schedules' => 'findSchedules',
            'get_work_schedule' => 'getSchedule',
            'add_holiday' => 'addHoliday',
            'update_holiday' => 'updateHoliday',
            'archive_holiday' => 'archiveHoliday',
            'restore_holiday' => 'restoreHoliday',
            'create_work_schedule' => 'createSchedule',
            'update_work_schedule' => 'updateSchedule',
            'set_default_schedule' => 'setDefault',
            'archive_work_schedule' => 'archiveSchedule',
            'restore_work_schedule' => 'restoreSchedule',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_holidays' => 'setup.schedule.view',
            'find_work_schedules' => 'setup.schedule.view',
            'get_work_schedule' => 'setup.schedule.view',
            'add_holiday' => 'setup.schedule.manage',
            'update_holiday' => 'setup.schedule.manage',
            'archive_holiday' => 'setup.schedule.manage',
            'restore_holiday' => 'setup.schedule.manage',
            'create_work_schedule' => 'setup.schedule.manage',
            'update_work_schedule' => 'setup.schedule.manage',
            'set_default_schedule' => 'setup.schedule.manage',
            'archive_work_schedule' => 'setup.schedule.manage',
            'restore_work_schedule' => 'setup.schedule.manage',
        ];
    }

    protected function confirmTools(): array
    {
        // Each changes whose day is what — for everybody on a schedule, or
        // everybody at all on a holiday's date.
        return ['update_work_schedule', 'set_default_schedule', 'archive_work_schedule', 'archive_holiday'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'setup.schedule.view';

        if ($user->cannot($permission)) {
            return $this->denied($permission === 'setup.schedule.manage' ? 'change work schedules or holidays' : 'view work schedules and holidays');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $manage = $this->allows($user, 'setup.schedule.manage')
            ? <<<'TXT'

            - add_holiday / update_holiday take a date as YYYY-MM-DD and a type; recurring repeats every year on the same date. archive_holiday waits for the user's confirmation; restore_holiday brings one back.
            - create_work_schedule makes a weekly schedule with the same hours on each working day: work_days, start and end (HH:MM; an end before the start crosses midnight), required_minutes (default 480), unpaid_break_minutes, grace_minutes, and for a flexible one core_start/core_end. update_work_schedule changes one; set_default_schedule and archive_work_schedule too. These three wait for the user's confirmation.
            - Rotations, split shifts, different hours on different days, and permanent deletion are done on the Work Schedule & Holidays screen (/setup/schedule), not here — say so if asked.
            TXT
            : '';

        return <<<TXT
        WORK SCHEDULES & HOLIDAYS — the shift templates people are assigned to, and the holiday calendar.
        - find_holidays lists holidays: upcoming by default, or for a year or a date range. find_work_schedules lists the schedules; get_work_schedule reads one day by day, with who works it. Who works which schedule on a given date is the attendance roster (find_shifts), not this.
        - Holiday types: regular and special (non-working) are days off; special (working) is an ordinary working day.{$manage}
        TXT;
    }

    public function tools(User $user): array
    {
        $holiday = ['type' => 'STRING', 'description' => 'The holiday, by name.'];
        $on = ['type' => 'STRING', 'description' => 'Its date (YYYY-MM-DD), when more than one holiday has the name.'];
        $schedule = ['type' => 'STRING', 'description' => 'The work schedule, by name.'];
        $hours = [
            'type' => ['type' => 'STRING', 'enum' => array_keys(self::SCHEDULE_TYPES), 'description' => 'fixed: late against the start; flexible: late only after the core hours open; hours_only: never late, only the hours count.'],
            'work_days' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING', 'enum' => self::WEEKDAYS], 'description' => 'The working days; the rest are rest days.'],
            'start' => ['type' => 'STRING', 'description' => 'HH:MM.'],
            'end' => ['type' => 'STRING', 'description' => 'HH:MM; at or before the start crosses midnight.'],
            'required_minutes' => ['type' => 'INTEGER', 'description' => 'Minutes required each working day.'],
            'unpaid_break_minutes' => ['type' => 'INTEGER'],
            'core_start' => ['type' => 'STRING', 'description' => 'Flexible only: when the core hours open (HH:MM).'],
            'core_end' => ['type' => 'STRING', 'description' => 'Flexible only: when they close (HH:MM).'],
            'grace_minutes' => ['type' => 'INTEGER', 'description' => 'Lateness forgiven each day.'],
            'attendance_policy' => ['type' => 'STRING', 'description' => 'The attendance policy its days are judged by, by name.'],
        ];

        return $this->permitted($user, [
            [
                'name' => 'find_holidays',
                'description' => 'List holidays in date order — the next twelve months by default, or a year, or a date range; archived ones when asked.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Part of the name.'],
                        'year' => ['type' => 'INTEGER'],
                        'from' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD.'],
                        'to' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD.'],
                        'type' => ['type' => 'STRING', 'enum' => array_keys(self::HOLIDAY_TYPES)],
                        'archived' => ['type' => 'BOOLEAN', 'description' => 'List archived holidays instead.'],
                    ],
                ],
            ],
            [
                'name' => 'find_work_schedules',
                'description' => 'List the work schedules with their hours, how many people are on each, and which is the company default.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Part of the name.'],
                        'archived' => ['type' => 'BOOLEAN', 'description' => 'List archived schedules instead.'],
                    ],
                ],
            ],
            [
                'name' => 'get_work_schedule',
                'description' => 'Read one work schedule day by day: hours, required time, grace, the attendance policy it names, and who works it.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['schedule' => $schedule],
                    'required' => ['schedule'],
                ],
            ],
            [
                'name' => 'add_holiday',
                'description' => 'Add a holiday to the calendar.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD.'],
                        'type' => ['type' => 'STRING', 'enum' => array_keys(self::HOLIDAY_TYPES)],
                        'recurring' => ['type' => 'BOOLEAN', 'description' => 'Repeats every year on the same date.'],
                    ],
                    'required' => ['name', 'date', 'type'],
                ],
            ],
            [
                'name' => 'update_holiday',
                'description' => "Change a holiday's name, date, type or yearly recurrence. Only the fields given change.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'holiday' => $holiday,
                        'on' => $on,
                        'new_name' => ['type' => 'STRING'],
                        'date' => ['type' => 'STRING', 'description' => 'The new date, YYYY-MM-DD.'],
                        'type' => ['type' => 'STRING', 'enum' => array_keys(self::HOLIDAY_TYPES)],
                        'recurring' => ['type' => 'BOOLEAN'],
                    ],
                    'required' => ['holiday'],
                ],
            ],
            [
                'name' => 'archive_holiday',
                'description' => 'Archive a holiday; its date becomes an ordinary working day. It can be restored.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['holiday' => $holiday, 'on' => $on],
                    'required' => ['holiday'],
                ],
            ],
            [
                'name' => 'restore_holiday',
                'description' => 'Restore an archived holiday.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['holiday' => $holiday, 'on' => $on],
                    'required' => ['holiday'],
                ],
            ],
            [
                'name' => 'create_work_schedule',
                'description' => 'Create a weekly work schedule with the same hours on each working day.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['name' => ['type' => 'STRING'], ...$hours],
                    'required' => ['name', 'work_days'],
                ],
            ],
            [
                'name' => 'update_work_schedule',
                'description' => "Change a work schedule's name, type, working days, hours, grace or attendance policy. Only the fields given change.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'schedule' => $schedule,
                        'new_name' => ['type' => 'STRING'],
                        ...$hours,
                        'clear_attendance_policy' => ['type' => 'BOOLEAN', 'description' => 'Stop naming a policy; the department or company one applies.'],
                    ],
                    'required' => ['schedule'],
                ],
            ],
            [
                'name' => 'set_default_schedule',
                'description' => "Make a schedule the company default — the hours of everyone with no schedule of their own or their department's — or clear the default.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'schedule' => $schedule,
                        'clear' => ['type' => 'BOOLEAN', 'description' => 'Clear the company default instead.'],
                    ],
                ],
            ],
            [
                'name' => 'archive_work_schedule',
                'description' => 'Archive a work schedule. People on it keep it; it can be restored.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['schedule' => $schedule],
                    'required' => ['schedule'],
                ],
            ],
            [
                'name' => 'restore_work_schedule',
                'description' => 'Restore an archived work schedule.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['schedule' => ['type' => 'STRING', 'description' => 'The archived schedule, by name.']],
                    'required' => ['schedule'],
                ],
            ],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'holiday', 'holidays', 'public holiday', 'public holidays', 'long weekend', 'work schedule',
            'work schedules', 'schedules', 'working hours', 'office hours', 'shift templates', 'rest day',
            'rest days', 'pista opisyal',
        ];
    }

    /**
     * The next holidays and the schedules, in a few lines.
     */
    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('setup.schedule.view') || ! app(Tenancy::class)->check()) {
            return null;
        }

        $today = CarbonImmutable::parse(OrganizationClock::today());
        $next = $this->occurrences(Holiday::query()->get(), $today, $today->addYear())->take(6);
        $default = $this->defaultScheduleId();

        $schedules = WorkSchedule::query()->withCount('employees')->orderBy('name')->limit(10)->get()
            ->map(fn (WorkSchedule $s): string => "{$s->name} ({$this->hoursSummary($s)}; {$s->employees_count} assigned".($s->id === $default ? '; company default' : '').')');

        return ContextSection::of('Schedules & holidays (today is '.$today->format('D, M j, Y').')', [
            $next->isEmpty()
                ? 'No holidays in the next twelve months.'
                : 'Next holidays: '.$next->map(fn (array $o): string => "{$o['holiday']->name} ({$o['date']->format('D, M j, Y')}, ".self::HOLIDAY_TYPES[$o['holiday']->type].')')->implode('; ').'.',
            $schedules->isEmpty() ? 'No work schedules have been set up.' : 'Work schedules: '.$schedules->implode('; ').'.',
            $default === null ? 'No company default schedule: anyone with no schedule of their own or their department’s works Mon–Fri 08:00–17:00.' : null,
        ]);
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        if ($tool === 'archive_holiday') {
            [$holiday] = $this->locateHoliday((string) ($args['holiday'] ?? ''), $args['on'] ?? null);

            if ($holiday === null) {
                return null;
            }

            $today = CarbonImmutable::parse(OrganizationClock::today());
            $next = $this->occurrences(collect([$holiday]), $today, $today->addYears(2))->first();

            return $next === null
                ? 'It has no date still to come, so no day not yet recorded changes.'
                : $next['date']->format('D, M j, Y').' becomes an ordinary working day for everyone; days already recorded keep how they were judged.';
        }

        if ($tool === 'set_default_schedule') {
            return 'The company default decides the hours of the '.$this->people($this->coverage()->onCompanyDefault()).' with no schedule of their own, their department’s or their location’s today.';
        }

        if (! in_array($tool, ['update_work_schedule', 'archive_work_schedule'], true)) {
            return null;
        }

        [$schedule] = $this->locateSchedule((string) ($args['schedule'] ?? ''));

        if ($schedule === null) {
            return null;
        }

        $working = $this->coverage()->onSchedule($schedule->id);

        return $tool === 'archive_work_schedule'
            ? "{$this->people($working)} ".($working === 1 ? 'works' : 'work').' it today and keep it — archiving only takes it off the pickers.'
            : "{$this->people($working)} ".($working === 1 ? 'works' : 'work').' it today; their days from now on follow the change, and days already recorded keep their shift.';
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findHolidays(User $user, array $args): ToolResult
    {
        $query = Holiday::query()
            ->when(($args['archived'] ?? false) === true, fn (Builder $q) => $q->onlyTrashed())
            ->when(filled($args['type'] ?? null), fn (Builder $q) => $q->where('type', $args['type']));

        $this->whereNameLike($query, (string) ($args['query'] ?? ''));

        if (($args['archived'] ?? false) === true) {
            $cards = $query->chronological()->limit(self::MAX_RESULTS)->get()
                ->map(fn (Holiday $h): array => $this->holidayCard($h, $h->date, 'find', 'neutral', 'Archived'))
                ->all();

            return ToolResult::found('Searched archived holidays', count($cards).' found', $cards);
        }

        [$from, $to, $error] = $this->range($args);

        if ($error !== null) {
            return ToolResult::error('Listed holidays', $error);
        }

        $found = $this->occurrences($query->get(), $from, $to);

        $cards = $found->take(self::MAX_RESULTS)
            ->map(fn (array $o): array => $this->holidayCard($o['holiday'], $o['date'], 'find', 'neutral', self::HOLIDAY_TYPES[$o['holiday']->type] ?? $o['holiday']->type))
            ->all();

        return ToolResult::found(
            'Listed holidays',
            count($cards).' between '.$from->format('M j, Y').' and '.$to->format('M j, Y'),
            $cards,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findSchedules(User $user, array $args): ToolResult
    {
        $archived = ($args['archived'] ?? false) === true;
        $default = $this->defaultScheduleId();

        $query = WorkSchedule::query()
            ->when($archived, fn (Builder $q) => $q->onlyTrashed())
            ->with(['days', 'attendancePolicy:id,name'])
            ->withCount('employees')
            ->orderBy('name')
            ->limit(self::MAX_RESULTS);

        $this->whereNameLike($query, (string) ($args['query'] ?? ''));

        $cards = $query->get()->map(fn (WorkSchedule $s): array => $this->card(
            kind: 'find',
            tone: 'neutral',
            badge: $archived ? 'Archived' : ($s->id === $default ? 'Company default' : self::SCHEDULE_TYPES[$s->type] ?? $s->type),
            title: $s->name,
            subtitle: $this->hoursSummary($s),
            meta: [
                $s->employees_count.' assigned',
                $s->attendancePolicy ? 'Judged by '.$s->attendancePolicy->name : null,
            ],
            id: $s->hashid,
        ))->all();

        return ToolResult::found($archived ? 'Searched archived schedules' : 'Listed work schedules', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getSchedule(User $user, array $args): ToolResult
    {
        [$schedule, $error] = $this->locateSchedule((string) ($args['schedule'] ?? ''));

        if ($schedule === null) {
            return ToolResult::error('Looked up the schedule', $error);
        }

        $schedule->load(['days', 'attendancePolicy:id,name'])->loadCount('employees');

        $isDefault = $this->defaultScheduleId() === $schedule->id;
        $departments = Department::query()->where('default_work_schedule_id', $schedule->id)->orderBy('name')->pluck('name');
        $working = $this->coverage()->onSchedule($schedule->id);

        return ToolResult::found("Read {$schedule->name}", null, [$this->card(
            kind: 'insight',
            tone: 'info',
            badge: $isDefault ? 'Company default' : self::SCHEDULE_TYPES[$schedule->type] ?? $schedule->type,
            title: $schedule->name,
            subtitle: self::SCHEDULE_TYPES[$schedule->type].' · '.($schedule->isRotating()
                ? "a {$schedule->cycle_length_days}-day rotation from ".$schedule->cycle_anchor_date?->toDateString()
                : 'weekly'),
            meta: [
                ...$this->dayLines($schedule),
                'Grace: '.PolicyDescription::duration((int) $schedule->grace_minutes).' a day',
                $schedule->weekly_required_minutes !== null ? 'Weekly required: '.PolicyDescription::duration((int) $schedule->weekly_required_minutes) : null,
                $schedule->attendancePolicy
                    ? 'Judged by the attendance policy '.$schedule->attendancePolicy->name
                    : 'Names no attendance policy (the department’s or the company’s applies)',
                $schedule->employees_count.' assigned; '.$this->people($working).' working it today',
                $isDefault ? 'The company default hours' : null,
                $departments->isNotEmpty() ? 'Department default for: '.$departments->take(10)->implode(', ') : null,
            ],
            id: $schedule->hashid,
        )]);
    }

    // ── Holidays ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function addHoliday(User $user, array $args): ToolResult
    {
        $date = $this->isoDate($args['date'] ?? null);

        if ($date === null) {
            return ToolResult::error('Added the holiday', 'Give the date as YYYY-MM-DD.');
        }

        $data = [
            'name' => trim((string) ($args['name'] ?? '')),
            'date' => $date,
            'type' => (string) ($args['type'] ?? ''),
            'is_recurring' => ($args['recurring'] ?? false) === true,
        ];

        if (($problem = $this->invalid($data, (new HolidayRequest)->rules())) !== null) {
            return ToolResult::error('Added the holiday', $problem);
        }

        $day = CarbonImmutable::parse($date);
        $same = Holiday::query()->whereRaw('lower(name) = ?', [Str::lower($data['name'])])->get()
            ->first(fn (Holiday $h): bool => $h->fallsOn($day) || ($data['is_recurring'] && $h->date !== null && $h->date->format('m-d') === $day->format('m-d')));

        if ($same !== null) {
            return ToolResult::error('Added the holiday', "“{$same->name}” is already on the calendar for {$same->date?->format('M j, Y')}".($same->is_recurring ? ', every year' : '').'.');
        }

        $holiday = $this->holidays->create($data, ' via assistant');

        return ToolResult::ok(
            "Added {$holiday->name}",
            $this->recordedNote($day, $data['is_recurring']),
            $this->holidayCard($holiday, $holiday->date, 'add', 'positive', 'Added'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateHoliday(User $user, array $args): ToolResult
    {
        [$holiday, $error] = $this->locateHoliday((string) ($args['holiday'] ?? ''), $args['on'] ?? null);

        if ($holiday === null) {
            return ToolResult::error('Looked up the holiday', $error);
        }

        $changes = [];

        if (filled($args['new_name'] ?? null)) {
            $changes['name'] = trim((string) $args['new_name']);
        }

        if (filled($args['date'] ?? null)) {
            $date = $this->isoDate($args['date']);

            if ($date === null) {
                return ToolResult::error('Updated the holiday', 'Give the date as YYYY-MM-DD.');
            }

            $changes['date'] = $date;
        }

        if (filled($args['type'] ?? null)) {
            $changes['type'] = (string) $args['type'];
        }

        if (is_bool($args['recurring'] ?? null)) {
            $changes['is_recurring'] = $args['recurring'];
        }

        if ($changes === []) {
            return ToolResult::error('Updated the holiday', 'Say what to change: its name, date, type or whether it repeats every year.');
        }

        $merged = [
            'name' => $holiday->name,
            'date' => $holiday->date?->toDateString(),
            'type' => $holiday->type,
            'is_recurring' => (bool) $holiday->is_recurring,
            ...$changes,
        ];

        if (($problem = $this->invalid($merged, (new HolidayRequest)->rules())) !== null) {
            return ToolResult::error('Updated the holiday', $problem);
        }

        $this->holidays->update($holiday, $changes, ' via assistant');
        $holiday->refresh();

        return ToolResult::ok(
            "Updated {$holiday->name}",
            $this->recordedNote(CarbonImmutable::parse($holiday->date), (bool) $holiday->is_recurring),
            $this->holidayCard($holiday, $holiday->date, 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archiveHoliday(User $user, array $args): ToolResult
    {
        [$holiday, $error] = $this->locateHoliday((string) ($args['holiday'] ?? ''), $args['on'] ?? null);

        if ($holiday === null) {
            return ToolResult::error('Looked up the holiday', $error);
        }

        $card = $this->holidayCard($holiday, $holiday->date, 'archive', 'warning', 'Archived');

        $this->holidays->archive($holiday, ' via assistant');

        return ToolResult::ok("Archived {$holiday->name}", 'Its date is an ordinary working day now; days already recorded keep how they were judged. It can be restored.', $card);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function restoreHoliday(User $user, array $args): ToolResult
    {
        [$holiday, $error] = $this->locateHoliday((string) ($args['holiday'] ?? ''), $args['on'] ?? null, archived: true);

        if ($holiday === null) {
            return ToolResult::error('Looked up the archived holiday', $error);
        }

        $this->holidays->restore($holiday, ' via assistant');

        return ToolResult::ok("Restored {$holiday->name}", null, $this->holidayCard($holiday, $holiday->date, 'start', 'positive', 'Restored'));
    }

    // ── Work schedules ───────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function createSchedule(User $user, array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));

        if ($this->nameTaken($name)) {
            return ToolResult::error('Created the schedule', "There is already a schedule called “{$name}”.");
        }

        [$policyId, $error] = $this->policyArgument($args);

        if ($error !== null) {
            return ToolResult::error('Created the schedule', $error);
        }

        $type = (string) ($args['type'] ?? 'fixed');
        $pattern = $this->pattern($type, [
            'work_days' => [],
            'start' => null,
            'end' => null,
            'required_minutes' => null,
            'unpaid_break_minutes' => 0,
            'core_start' => null,
            'core_end' => null,
        ], $args);

        $attributes = [
            'name' => $name,
            'type' => $type,
            'cycle_length_days' => WorkSchedule::WEEKLY_CYCLE_LENGTH,
            'cycle_anchor_date' => null,
            'grace_minutes' => (int) ($args['grace_minutes'] ?? 0),
            'weekly_required_minutes' => null,
            'attendance_policy_id' => $policyId,
        ];

        if ($this->lacksHours($pattern)) {
            return ToolResult::error('Created the schedule', 'Give the hours as a start and an end (HH:MM).');
        }

        if (($problem = $this->invalidSchedule($attributes, $pattern)) !== null) {
            return ToolResult::error('Created the schedule', $problem);
        }

        $schedule = $this->schedules->create($attributes, $pattern, ' via assistant');

        return ToolResult::ok("Created {$schedule->name}", $this->hoursSummary($schedule->load('days')), $this->scheduleCard($schedule, 'add', 'positive', 'Created'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateSchedule(User $user, array $args): ToolResult
    {
        [$schedule, $error] = $this->locateSchedule((string) ($args['schedule'] ?? ''));

        if ($schedule === null) {
            return ToolResult::error('Looked up the schedule', $error);
        }

        $changes = [];

        if (filled($args['new_name'] ?? null)) {
            $changes['name'] = trim((string) $args['new_name']);

            if ($this->nameTaken($changes['name'], except: $schedule)) {
                return ToolResult::error('Updated the schedule', "There is already a schedule called “{$changes['name']}”.");
            }
        }

        if (isset($args['grace_minutes'])) {
            $changes['grace_minutes'] = (int) $args['grace_minutes'];
        }

        [$policyId, $error] = $this->policyArgument($args);

        if ($error !== null) {
            return ToolResult::error('Updated the schedule', $error);
        }

        if ($policyId !== null) {
            $changes['attendance_policy_id'] = $policyId;
        } elseif (($args['clear_attendance_policy'] ?? false) === true) {
            $changes['attendance_policy_id'] = null;
        }

        $pattern = null;

        if (array_intersect(self::PATTERN_ARGS, array_keys($args)) !== []) {
            $current = $this->uniformPattern($schedule);

            if ($current === null) {
                return ToolResult::error(
                    'Updated the schedule',
                    "{$schedule->name} has ".($schedule->isRotating() ? 'a rotation' : 'different hours on different days, a split shift or start/end limits').', so its hours are changed on the Work Schedule & Holidays screen (/setup/schedule) — rewriting it with one set of hours would lose that.',
                );
            }

            $changes['type'] = (string) ($args['type'] ?? $schedule->type);
            $pattern = $this->pattern($changes['type'], $current, $args);
        }

        if ($changes === []) {
            return ToolResult::error('Updated the schedule', 'Say what to change: its name, type, working days, hours, grace or attendance policy.');
        }

        $attributes = [
            ...Arr::only($schedule->getAttributes(), ['name', 'type', 'cycle_length_days', 'grace_minutes', 'weekly_required_minutes', 'attendance_policy_id']),
            'cycle_anchor_date' => $schedule->cycle_anchor_date?->toDateString(),
            ...$changes,
        ];

        if ($pattern !== null && $this->lacksHours($pattern)) {
            return ToolResult::error('Updated the schedule', 'Give the hours as a start and an end (HH:MM).');
        }

        $days = $pattern ?? $this->currentDays($schedule);

        if (($problem = $this->invalidSchedule($attributes, $days)) !== null) {
            return ToolResult::error('Updated the schedule', $problem);
        }

        $this->schedules->update($schedule, $changes, $pattern, ' via assistant');
        $schedule->refresh()->load('days');

        return ToolResult::ok(
            "Updated {$schedule->name}",
            'Days already recorded keep their shift; HR can re-apply them from the attendance board.',
            $this->scheduleCard($schedule, 'edit', 'info', 'Updated'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setDefault(User $user, array $args): ToolResult
    {
        if (($args['clear'] ?? false) === true) {
            $this->schedules->setDefault(null, ' via assistant');

            return ToolResult::ok('Cleared the company default schedule', 'Anyone with no schedule of their own or their department’s now works the built-in Mon–Fri 08:00–17:00.', $this->card(
                kind: 'edit',
                tone: 'warning',
                badge: 'Default cleared',
                title: 'Company default schedule',
                subtitle: 'Built-in Mon–Fri 08:00–17:00',
            ));
        }

        [$schedule, $error] = $this->locateSchedule((string) ($args['schedule'] ?? ''));

        if ($schedule === null) {
            return ToolResult::error('Looked up the schedule', $error);
        }

        if ($this->defaultScheduleId() === $schedule->id) {
            return ToolResult::error('Set the company default', "{$schedule->name} is already the company default.");
        }

        $this->schedules->setDefault($schedule, ' via assistant');

        return ToolResult::ok("Made {$schedule->name} the company default", null, $this->scheduleCard($schedule->load('days'), 'edit', 'info', 'Company default'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archiveSchedule(User $user, array $args): ToolResult
    {
        [$schedule, $error] = $this->locateSchedule((string) ($args['schedule'] ?? ''));

        if ($schedule === null) {
            return ToolResult::error('Looked up the schedule', $error);
        }

        $card = $this->scheduleCard($schedule->load('days')->loadCount('employees'), 'archive', 'warning', 'Archived');

        $this->schedules->archive($schedule, ' via assistant');

        return ToolResult::ok("Archived {$schedule->name}", 'The people on it keep it; it can be restored.', $card);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function restoreSchedule(User $user, array $args): ToolResult
    {
        [$schedule, $error] = $this->locateSchedule((string) ($args['schedule'] ?? ''), archived: true);

        if ($schedule === null) {
            return ToolResult::error('Looked up the archived schedule', $error);
        }

        $this->schedules->restore($schedule, ' via assistant');

        return ToolResult::ok("Restored {$schedule->name}", null, $this->scheduleCard($schedule->load('days'), 'start', 'positive', 'Restored'));
    }

    // ── Patterns ─────────────────────────────────────────────────────────────

    /**
     * A plain week from one set of hours: each working day gets the same
     * segment, required minutes, unpaid break and (flexible) core hours; every
     * other day is a rest day. What the arguments do not say comes from `$base`
     * — the schedule's current hours, or nothing for a new one.
     *
     * An hours-only day still carries a segment, as on the screen: a working day
     * without one is written as a rest day.
     *
     * @param  array{work_days: list<string>, start: ?string, end: ?string, required_minutes: ?int, unpaid_break_minutes: int, core_start: ?string, core_end: ?string}  $base
     * @param  array<string, mixed>  $args
     * @return list<array<string, mixed>>
     */
    private function pattern(string $type, array $base, array $args): array
    {
        $workDays = array_key_exists('work_days', $args) ? array_values(array_intersect(self::WEEKDAYS, (array) $args['work_days'])) : $base['work_days'];
        $start = array_key_exists('start', $args) ? $this->clock($args['start']) : $base['start'];
        $end = array_key_exists('end', $args) ? $this->clock($args['end']) : $base['end'];

        if ($type === 'hours_only' && ($start === null || $end === null)) {
            [$start, $end] = ['08:00', '17:00'];
        }

        $core = $type === 'flexible'
            ? [
                array_key_exists('core_start', $args) ? $this->clock($args['core_start']) : $base['core_start'],
                array_key_exists('core_end', $args) ? $this->clock($args['core_end']) : $base['core_end'],
            ]
            : [null, null];

        $required = isset($args['required_minutes']) ? (int) $args['required_minutes'] : ($base['required_minutes'] ?? 480);
        $break = isset($args['unpaid_break_minutes']) ? (int) $args['unpaid_break_minutes'] : $base['unpaid_break_minutes'];

        return array_map(fn (string $day): array => in_array($day, $workDays, true)
            ? [
                'is_rest_day' => false,
                'segments' => [['start' => $start, 'end' => $end]],
                'required_minutes' => $required,
                'unpaid_break_minutes' => $break,
                'core_start' => $core[0],
                'core_end' => $core[1],
            ]
            : ['is_rest_day' => true, 'segments' => [], 'required_minutes' => 0], self::WEEKDAYS);
    }

    /**
     * Whether a working day in the pattern has no start or end to its hours.
     *
     * @param  list<array<string, mixed>>  $pattern
     */
    private function lacksHours(array $pattern): bool
    {
        foreach ($pattern as $day) {
            foreach ($day['segments'] ?? [] as $segment) {
                if (($segment['start'] ?? null) === null || ($segment['end'] ?? null) === null) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A weekly schedule's hours, when every working day has the same ones — or
     * null when it is a rotation, has a split shift, different hours on
     * different days, or start/end limits, which one set of hours cannot say.
     *
     * @return array{work_days: list<string>, start: ?string, end: ?string, required_minutes: ?int, unpaid_break_minutes: int, core_start: ?string, core_end: ?string}|null
     */
    private function uniformPattern(WorkSchedule $schedule): ?array
    {
        if ($schedule->isRotating()) {
            return null;
        }

        $working = $schedule->patternDays()->filter(fn (WorkScheduleDay $day): bool => ! $day->is_rest_day);

        $shapes = $working->map(fn (WorkScheduleDay $day): string => json_encode([
            array_values($day->segments ?? []),
            (int) $day->required_minutes,
            (int) $day->unpaid_break_minutes,
            WorkSchedule::clockFace($day->core_start),
            WorkSchedule::clockFace($day->core_end),
            WorkSchedule::clockFace($day->earliest_start),
            WorkSchedule::clockFace($day->latest_end),
        ]))->unique();

        $first = $working->first();

        if ($shapes->count() > 1 || count($first?->segments ?? []) > 1 || filled($first?->earliest_start) || filled($first?->latest_end)) {
            return null;
        }

        $segment = $first?->segments[0] ?? null;

        return [
            'work_days' => $working->keys()->map(fn (int $index): string => self::WEEKDAYS[$index - 1])->values()->all(),
            'start' => $segment['start'] ?? null,
            'end' => $segment['end'] ?? null,
            'required_minutes' => $first !== null ? (int) $first->required_minutes : null,
            'unpaid_break_minutes' => (int) ($first?->unpaid_break_minutes ?? 0),
            'core_start' => WorkSchedule::clockFace($first?->core_start),
            'core_end' => WorkSchedule::clockFace($first?->core_end),
        ];
    }

    /**
     * The schedule's cycle as the form would post it, to validate a change that
     * leaves the pattern alone.
     *
     * @return list<array<string, mixed>>
     */
    private function currentDays(WorkSchedule $schedule): array
    {
        return $schedule->patternDays()->sortKeys()->values()->map(fn (WorkScheduleDay $day): array => [
            'is_rest_day' => (bool) $day->is_rest_day,
            'segments' => array_values($day->segments ?? []),
            'required_minutes' => (int) $day->required_minutes,
            'unpaid_break_minutes' => (int) $day->unpaid_break_minutes,
            'core_start' => WorkSchedule::clockFace($day->core_start),
            'core_end' => WorkSchedule::clockFace($day->core_end),
            'earliest_start' => WorkSchedule::clockFace($day->earliest_start),
            'latest_end' => WorkSchedule::clockFace($day->latest_end),
        ])->all();
    }

    /**
     * A schedule and its days against the screen's own rules and pattern checks,
     * with the problem named the way the tool names the field.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $days
     */
    private function invalidSchedule(array $attributes, array $days): ?string
    {
        $data = [...$attributes, 'days' => $days];

        return $this->invalid(
            $data,
            (new WorkScheduleRequest)->rules(),
            [],
            [
                'days.*.segments.*.start' => 'start',
                'days.*.segments.*.end' => 'end',
                'days.*.required_minutes' => 'required minutes',
                'days.*.unpaid_break_minutes' => 'unpaid break minutes',
                'days.*.core_start' => 'core start',
                'days.*.core_end' => 'core end',
                'attendance_policy_id' => 'attendance policy',
            ],
            fn (Validator $validator) => WorkScheduleRequest::validatePattern($validator, $data),
        );
    }

    /**
     * "8:00" → "08:00"; anything else is passed on for the rules to refuse.
     */
    private function clock(mixed $value): ?string
    {
        $value = trim(is_scalar($value) ? (string) $value : '');

        if ($value === '') {
            return null;
        }

        return preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', $value, $m) === 1
            ? str_pad($m[1], 2, '0', STR_PAD_LEFT).':'.$m[2]
            : $value;
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one schedule by name — an exact name first, then a partial name
     * only one schedule has.
     *
     * @return array{0: WorkSchedule|null, 1: string}
     */
    private function locateSchedule(string $needle, bool $archived = false): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [null, 'Say which schedule.'];
        }

        $base = fn (): Builder => $archived ? WorkSchedule::onlyTrashed() : WorkSchedule::query();

        $matches = $base()->whereRaw('lower(name) = ?', [Str::lower($needle)])->limit(2)->get();

        if ($matches->isEmpty()) {
            $matches = $this->whereNameLike($base(), $needle)->orderBy('name')->limit(6)->get();
        }

        return match (true) {
            $matches->isEmpty() => [null, ($archived ? 'No archived schedule' : 'No schedule').' matches “'.Str::limit($needle, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one schedule matches “'.Str::limit($needle, 60).'”: '.$matches->pluck('name')->implode(', ').'.'],
        };
    }

    /**
     * Exactly one holiday by name, narrowed by a date when two share it.
     *
     * @return array{0: Holiday|null, 1: string}
     */
    private function locateHoliday(string $needle, mixed $on, bool $archived = false): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [null, 'Say which holiday.'];
        }

        $base = fn (): Builder => $archived ? Holiday::onlyTrashed() : Holiday::query();

        $matches = $base()->whereRaw('lower(name) = ?', [Str::lower($needle)])->get();

        if ($matches->isEmpty()) {
            $matches = $this->whereNameLike($base(), $needle)->chronological()->limit(10)->get();
        }

        $date = $this->isoDate($on);

        if ($date !== null) {
            $day = CarbonImmutable::parse($date);
            $matches = $matches->filter(fn (Holiday $h): bool => $h->fallsOn($day))->values();
        }

        return match (true) {
            $matches->isEmpty() => [null, ($archived ? 'No archived holiday' : 'No holiday').' matches “'.Str::limit($needle, 60).'”'.($date !== null ? " on {$date}" : '').'.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one holiday matches: '.$matches->map(fn (Holiday $h): string => "{$h->name} ({$h->date?->format('M j, Y')})")->implode(', ').'. Say which date.'],
        };
    }

    /**
     * The policy an `attendance_policy` argument names, as an id — or why not.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: int|null, 1: string|null}
     */
    private function policyArgument(array $args): array
    {
        $needle = trim((string) ($args['attendance_policy'] ?? ''));

        if ($needle === '') {
            return [null, null];
        }

        $id = $this->resolveId(AttendancePolicy::query(), 'name', $needle);

        if ($id === null) {
            $matches = $this->whereNameLike(AttendancePolicy::query(), $needle)->limit(3)->pluck('id');
            $id = $matches->count() === 1 ? (int) $matches->first() : null;
        }

        return $id !== null
            ? [$id, null]
            : [null, 'No attendance policy matches “'.Str::limit($needle, 60).'”. The policies are: '.$this->catalog(AttendancePolicy::query()->orderBy('name')->pluck('name')).'.'];
    }

    /**
     * Whether a live schedule other than `$except` already has this name. The
     * screen does not insist; here it keeps a repeated request from making a
     * second schedule nobody can then name.
     */
    private function nameTaken(string $name, ?WorkSchedule $except = null): bool
    {
        return $name !== '' && WorkSchedule::query()
            ->whereRaw('lower(name) = ?', [Str::lower($name)])
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->id))
            ->exists();
    }

    /**
     * Narrow a query to names containing the needle — ILIKE on Postgres, LIKE
     * elsewhere, with % and _ taken literally.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function whereNameLike(Builder $query, string $needle): Builder
    {
        $needle = trim($needle);

        if ($needle === '') {
            return $query;
        }

        $like = $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return $query->where('name', $like, '%'.addcslashes($needle, '%_\\').'%');
    }

    // ── Dates ────────────────────────────────────────────────────────────────

    /**
     * The range a holiday list covers: a year, a from/to pair, or the next
     * twelve months — or why the arguments do not make one.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string|null}
     */
    private function range(array $args): array
    {
        $today = CarbonImmutable::parse(OrganizationClock::today());

        if (isset($args['year']) && (int) $args['year'] >= 1900 && (int) $args['year'] <= 2200) {
            $start = CarbonImmutable::create((int) $args['year'], 1, 1);

            return [$start, $start->endOfYear()->startOfDay(), null];
        }

        $from = filled($args['from'] ?? null) ? $this->isoDate($args['from']) : $today->toDateString();
        $to = filled($args['to'] ?? null) ? $this->isoDate($args['to']) : null;

        if ($from === null || (filled($args['to'] ?? null) && $to === null)) {
            return [$today, $today, 'Give dates as YYYY-MM-DD.'];
        }

        $start = CarbonImmutable::parse($from);
        $end = $to !== null ? CarbonImmutable::parse($to) : $start->addYear()->subDay();

        if ($end->lt($start)) {
            return [$start, $end, 'The range ends before it starts.'];
        }

        if ($start->diffInDays($end) > self::MAX_RANGE_DAYS) {
            return [$start, $end, 'That range is too long — at most two years at a time.'];
        }

        return [$start, $end, null];
    }

    /**
     * Every date each holiday falls on within an inclusive range — a yearly one
     * on each year the range spans (a 29 February only in leap years, as
     * {@see Holiday::fallsOn()} has it) — in date order.
     *
     * @param  Collection<int, Holiday>  $holidays
     * @return Collection<int, array{holiday: Holiday, date: CarbonImmutable}>
     */
    private function occurrences(Collection $holidays, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $found = collect();

        foreach ($holidays as $holiday) {
            if ($holiday->date === null) {
                continue;
            }

            $dates = $holiday->is_recurring
                ? array_filter(array_map(
                    fn (int $year): ?CarbonImmutable => checkdate($holiday->date->month, $holiday->date->day, $year)
                        ? CarbonImmutable::create($year, $holiday->date->month, $holiday->date->day)
                        : null,
                    range($from->year, $to->year),
                ))
                : [CarbonImmutable::parse($holiday->date->toDateString())];

            foreach ($dates as $date) {
                if ($date->betweenIncluded($from, $to)) {
                    $found->push(['holiday' => $holiday, 'date' => $date]);
                }
            }
        }

        return $found->sortBy(fn (array $o): string => $o['date']->toDateString().' '.$o['holiday']->name)->values();
    }

    /**
     * What a holiday change does to days on or before today, which were judged
     * already — or null when its date is still to come.
     */
    private function recordedNote(CarbonImmutable $date, bool $recurring): ?string
    {
        if ($recurring || $date->toDateString() > OrganizationClock::today()) {
            return null;
        }

        return 'That date has passed: days already recorded keep how they were judged until HR re-applies them from the attendance board.';
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /**
     * The pattern in lines, runs of identical days together: "Mon–Fri:
     * 08:00–17:00 · 8h required, 1h unpaid break", "Sat–Sun: rest day".
     *
     * @return list<string>
     */
    private function dayLines(WorkSchedule $schedule): array
    {
        $rotating = $schedule->isRotating();
        $runs = [];

        foreach ($schedule->patternDays()->sortKeys() as $index => $day) {
            $label = $rotating ? "Day {$index}" : (self::WEEKDAYS[$index - 1] ?? "Day {$index}");
            $text = $this->describeDay($schedule, $day);
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last]['text'] === $text) {
                $runs[$last]['to'] = $label;
            } else {
                $runs[] = ['from' => $label, 'to' => $label, 'text' => $text];
            }
        }

        $lines = array_map(
            fn (array $run): string => ($run['from'] === $run['to'] ? $run['from'] : "{$run['from']}–{$run['to']}").': '.$run['text'],
            $runs,
        );

        return count($lines) > self::MAX_DAY_LINES
            ? [...array_slice($lines, 0, self::MAX_DAY_LINES), 'and '.(count($lines) - self::MAX_DAY_LINES).' more stretches of the rotation']
            : $lines;
    }

    private function describeDay(WorkSchedule $schedule, WorkScheduleDay $day): string
    {
        if ($day->is_rest_day) {
            return 'rest day';
        }

        $required = PolicyDescription::duration((int) $day->required_minutes);

        if ($schedule->type === 'hours_only') {
            return "{$required} (any hours)";
        }

        $segments = implode(' · ', array_map(fn (array $s): string => "{$s['start']}–{$s['end']}", array_values($day->segments ?? [])));

        return implode(', ', array_filter([
            "{$segments} · {$required} required",
            (int) $day->unpaid_break_minutes > 0 ? PolicyDescription::duration((int) $day->unpaid_break_minutes).' unpaid break' : null,
            $schedule->type === 'flexible' && $day->core_start !== null
                ? 'core '.WorkSchedule::clockFace($day->core_start).'–'.WorkSchedule::clockFace($day->core_end)
                : null,
        ]));
    }

    /**
     * The first stretch or two of the pattern, for a list or a brief.
     */
    private function hoursSummary(WorkSchedule $schedule): string
    {
        $lines = array_values(array_filter($this->dayLines($schedule), fn (string $line): bool => ! str_ends_with($line, 'rest day')));

        return ($lines === [] ? 'no working days' : implode('; ', array_slice($lines, 0, 2)).(count($lines) > 2 ? '; …' : ''))
            .($schedule->isRotating() ? " ({$schedule->cycle_length_days}-day rotation)" : '');
    }

    private function defaultScheduleId(): ?int
    {
        $id = app(Tenancy::class)->organization()?->default_work_schedule_id;

        return $id !== null ? (int) $id : null;
    }

    /**
     * Who is reached today, resolved afresh: the module list is a singleton,
     * and a count remembered from an earlier call would be a stale one.
     */
    private function coverage(): AttendanceCoverage
    {
        return new AttendanceCoverage;
    }

    private function people(int $count): string
    {
        return $count.' '.Str::plural('person', $count);
    }

    /**
     * @return array<string, mixed>
     */
    private function holidayCard(Holiday $holiday, mixed $date, string $kind, string $tone, string $badge): array
    {
        $date = $date !== null ? CarbonImmutable::parse($date) : null;

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $holiday->name,
            subtitle: trim(($date?->format('D, M j, Y') ?? 'No date').($holiday->is_recurring ? ' · every year' : '')),
            meta: [
                self::HOLIDAY_TYPES[$holiday->type] ?? $holiday->type,
                $holiday->type === 'special_working' ? 'An ordinary working day' : 'A day off',
            ],
            id: $holiday->hashid,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleCard(WorkSchedule $schedule, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $schedule->name,
            subtitle: $this->hoursSummary($schedule),
            meta: [
                self::SCHEDULE_TYPES[$schedule->type] ?? $schedule->type,
                'Grace: '.PolicyDescription::duration((int) $schedule->grace_minutes),
                isset($schedule->employees_count) ? $schedule->employees_count.' assigned' : null,
            ],
            id: $schedule->hashid,
        );
    }
}
