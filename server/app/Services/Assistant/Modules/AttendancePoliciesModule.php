<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Setup\AttendancePolicyPreviewRequest;
use App\Http\Requests\Setup\AttendancePolicyRequest;
use App\Models\AttendancePolicy;
use App\Models\Department;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\Attendance\AttendanceCoverage;
use App\Support\Attendance\AttendancePolicyPresets;
use App\Support\Attendance\AttendancePolicySettings;
use App\Support\Attendance\PolicyDescription;
use App\Support\Attendance\PolicyResolver;
use App\Support\Attendance\ShiftResolver;
use App\Support\Attendance\WorkedExample;
use App\Support\OrganizationClock;
use App\Support\Setup\AttendancePolicyException;
use App\Support\Setup\AttendancePolicyWorkflow;
use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Attendance Policies capability (ADR 0038): how a day is judged — grace,
 * lateness and short-day thresholds, rounding, breaks, overtime, night
 * differential — and which policy judges whom.
 *
 * **Reading** answers "how do we handle overtime?", "what does the Shift work
 * policy do?", "which rules is Maria judged by?", and — through the screen's own
 * worked example ({@see WorkedExample}, the real evaluator, writing nothing) —
 * "if someone clocks in at 8:20 and out at 6:30, what happens?". **Doing**
 * creates policies from a preset, adjusts their typed options, makes one the
 * company default, archives and restores, through {@see AttendancePolicyWorkflow}
 * against the screen's own rules ({@see AttendancePolicyRequest}: the per-field
 * rules and the checks that span fields, in the screen's words).
 *
 * Deliberately left to the screen:
 *
 * - **Capture** — the ways a punch may arrive, selfies, geofencing, the office
 *   networks, the offline window and clock skew. They are security controls on
 *   punching, and a chat that reads other people's text is not where they are
 *   loosened. Read-outs say what they amount to, never the network addresses.
 * - **Punch windows** (early clock-in, maximum shift span): engine plumbing.
 * - **Permanent deletion.**
 *
 * Changing a policy, making one the default and archiving one wait for the
 * user's Confirm (ADR 0049), and the card says how many people's days the
 * policy judges today ({@see AttendanceCoverage}). A new policy judges nobody
 * until it is named or made the default, so creating one does not wait.
 * Everything needs `setup.attendance-policies.view`; changes
 * `setup.attendance-policies.manage`; which policy judges a person also needs
 * `employees.view`.
 */
class AttendancePoliciesModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    /** How many policies a list returns. */
    private const MAX_RESULTS = 20;

    /** What the fallback is called when somebody asks about it. */
    private const BUILT_IN = 'Built-in rules';

    /**
     * The settings a chat may set: tool parameter => [path in the settings
     * document, parameter schema]. Capture and punch windows are absent on
     * purpose (see the class note).
     *
     * @var array<string, array{0: string, 1: array<string, mixed>}>
     */
    private const SETTINGS = [
        'lateness_enabled' => ['lateness.enabled', ['type' => 'BOOLEAN', 'description' => 'Off: nobody is ever late.']],
        'grace_minutes' => ['lateness.grace_minutes', ['type' => 'INTEGER', 'description' => 'Lateness forgiven each day.']],
        'grace_mode' => ['lateness.grace_mode', ['type' => 'STRING', 'enum' => AttendancePolicySettings::GRACE_MODES, 'description' => 'per_day, or monthly_allowance: one pool for the month.']],
        'monthly_grace_minutes' => ['lateness.monthly_grace_minutes', ['type' => 'INTEGER', 'description' => 'The monthly pool.']],
        'late_half_day_after_minutes' => ['lateness.half_day_after_minutes', ['type' => 'INTEGER', 'description' => 'Later than this is a half day; 0 turns it off.']],
        'late_absent_after_minutes' => ['lateness.absent_after_minutes', ['type' => 'INTEGER', 'description' => 'Later than this is absent; 0 turns it off.']],
        'undertime_basis' => ['undertime.basis', ['type' => 'STRING', 'enum' => AttendancePolicySettings::UNDERTIME_BASES, 'description' => 'schedule: short by leaving early; hours: short only by the hours.']],
        'short_half_day_below_minutes' => ['undertime.half_day_below_minutes', ['type' => 'INTEGER', 'description' => 'Fewer worked minutes is a half day; 0 turns it off.']],
        'minimum_minutes_for_present' => ['undertime.minimum_minutes_for_present', ['type' => 'INTEGER', 'description' => 'Fewer is absent; 0 turns it off.']],
        'rounding_mode' => ['rounding.mode', ['type' => 'STRING', 'enum' => AttendancePolicySettings::ROUNDING_MODES]],
        'rounding_unit' => ['rounding.unit', ['type' => 'INTEGER', 'description' => '5, 10, 15 or 30 minutes.']],
        'rounding_apply_to' => ['rounding.apply_to', ['type' => 'STRING', 'enum' => AttendancePolicySettings::ROUNDING_TARGETS]],
        'paid_break_minutes' => ['breaks.paid_break_minutes', ['type' => 'INTEGER', 'description' => 'This much of a punched break counts as worked.']],
        'auto_deduct_break_minutes' => ['breaks.auto_deduct_minutes', ['type' => 'INTEGER', 'description' => 'Unpaid break taken off a day on which none was punched.']],
        'auto_deduct_after_minutes' => ['breaks.auto_deduct_after_worked_minutes', ['type' => 'INTEGER', 'description' => '…once the day has run this long.']],
        'max_break_minutes' => ['breaks.max_break_minutes', ['type' => 'INTEGER', 'description' => 'A longer break is flagged; 0 turns it off.']],
        'overtime_basis' => ['overtime.basis', ['type' => 'STRING', 'enum' => AttendancePolicySettings::OVERTIME_BASES]],
        'overtime_daily_after_minutes' => ['overtime.daily_after_minutes', ['type' => 'INTEGER', 'description' => 'Overtime beyond this much in a day.']],
        'overtime_weekly_after_minutes' => ['overtime.weekly_after_minutes', ['type' => 'INTEGER', 'description' => 'Overtime once the week passes this.']],
        'overtime_min_block_minutes' => ['overtime.min_block_minutes', ['type' => 'INTEGER', 'description' => 'Less than this in a day is not overtime.']],
        'overtime_requires_approval' => ['overtime.requires_approval', ['type' => 'BOOLEAN']],
        'overtime_count_early_clock_in' => ['overtime.count_early_clock_in', ['type' => 'BOOLEAN', 'description' => 'Off: minutes before a fixed shift starts are not worked.']],
        'rest_day_all_overtime' => ['overtime.rest_day_all_overtime', ['type' => 'BOOLEAN']],
        'holiday_all_overtime' => ['overtime.holiday_all_overtime', ['type' => 'BOOLEAN']],
        'missing_clock_out' => ['missing_clock_out.action', ['type' => 'STRING', 'enum' => AttendancePolicySettings::MISSING_CLOCK_OUT_ACTIONS]],
        'missing_clock_out_after_minutes' => ['missing_clock_out.after_minutes', ['type' => 'INTEGER', 'description' => 'For auto_close_after_minutes.']],
        'clock_in_reminder_after_minutes' => ['reminders.clock_in_after_minutes', ['type' => 'INTEGER', 'description' => 'Remind this far into a shift; 0 turns it off.']],
        'night_enabled' => ['night.enabled', ['type' => 'BOOLEAN', 'description' => 'Night differential.']],
        'night_start' => ['night.start', ['type' => 'STRING', 'description' => 'HH:MM.']],
        'night_end' => ['night.end', ['type' => 'STRING', 'description' => 'HH:MM.']],
    ];

    /**
     * Thresholds that are off when unset — a 0 from the model means off.
     *
     * @var list<string>
     */
    private const ZERO_IS_OFF = [
        'late_half_day_after_minutes', 'late_absent_after_minutes', 'short_half_day_below_minutes',
        'minimum_minutes_for_present', 'max_break_minutes', 'clock_in_reminder_after_minutes',
    ];

    /**
     * Why a policy applies to a person, said the way somebody would say it.
     *
     * @var array<string, string>
     */
    private const SOURCES = [
        'assignment' => 'named on their own schedule assignment',
        'schedule' => 'named on their schedule',
        'department' => 'named on their department',
        'location' => 'named on their work location',
        'organization' => 'the company default',
        'fallback' => 'nothing names a policy for them, so the built-in rules apply',
    ];

    public function __construct(
        private readonly AttendancePolicyWorkflow $workflow,
    ) {}

    public function key(): string
    {
        return 'attendance-policies';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('setup.attendance-policies.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_attendance_policies' => 'findPolicies',
            'get_attendance_policy' => 'getPolicy',
            'get_worked_example' => 'workedExample',
            'get_applicable_policy' => 'applicablePolicy',
            'create_attendance_policy' => 'createPolicy',
            'update_attendance_policy' => 'updatePolicy',
            'set_default_attendance_policy' => 'setDefault',
            'archive_attendance_policy' => 'archivePolicy',
            'restore_attendance_policy' => 'restorePolicy',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_attendance_policies' => 'setup.attendance-policies.view',
            'get_attendance_policy' => 'setup.attendance-policies.view',
            'get_worked_example' => 'setup.attendance-policies.view',
            // A person is looked up in the directory, so the directory's own
            // permission is checked too — in tools() and at run time.
            'get_applicable_policy' => 'setup.attendance-policies.view',
            'create_attendance_policy' => 'setup.attendance-policies.manage',
            'update_attendance_policy' => 'setup.attendance-policies.manage',
            'set_default_attendance_policy' => 'setup.attendance-policies.manage',
            'archive_attendance_policy' => 'setup.attendance-policies.manage',
            'restore_attendance_policy' => 'setup.attendance-policies.manage',
        ];
    }

    protected function confirmTools(): array
    {
        // Each changes how the days of everybody the policy judges come out.
        return ['update_attendance_policy', 'set_default_attendance_policy', 'archive_attendance_policy'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'setup.attendance-policies.view';

        if ($user->cannot($permission)) {
            return $this->denied($permission === 'setup.attendance-policies.manage' ? 'change attendance policies' : 'view attendance policies');
        }

        // Checked before any name is looked up, so a made-up name and a real one
        // get the same answer from somebody who may not see the directory.
        if ($tool === 'get_applicable_policy' && $user->cannot('employees.view')) {
            return $this->denied('look up who is judged by which policy');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $applicable = $this->allows($user, 'employees.view')
            ? ' get_applicable_policy says which policy judges a person on a date, and why.'
            : '';

        $manage = $this->allows($user, 'setup.attendance-policies.manage')
            ? <<<'TXT'

            - create_attendance_policy makes one, from a preset (or the built-in rules) with any settings adjusted; it judges nobody until it is named on a schedule, department or assignment, or made the default.
            - update_attendance_policy changes a policy's settings; set_default_attendance_policy makes one the company default (or clears it); archive_attendance_policy archives one. These wait for the user's confirmation. restore_attendance_policy brings one back.
            - Settings are whole minutes. For a threshold that can be off (half day, absent, minimum, max break, reminder), 0 turns it off. grace_from_schedule uses each schedule's own grace; overtime_after_required_hours makes daily overtime start after the day's required hours.
            - Capture (punch sources, selfies, geofencing, office networks, offline window, clock skew), punch windows and permanent deletion are set on the Attendance Policies screen (/setup/attendance-policies), not here — say so if asked.
            TXT
            : '';

        $presets = implode('; ', array_map(fn (array $p): string => "{$p['key']} ({$p['name']})", AttendancePolicyPresets::all()));

        return <<<TXT
        ATTENDANCE POLICIES — how a day is judged: grace, lateness and short-day thresholds, rounding, breaks, overtime, night differential. Which policy judges a person: their assignment's, else their schedule's, department's, work location's, the company default, else the built-in rules.
        - find_attendance_policies lists them; get_attendance_policy explains one (or a preset, or the built-in rules). get_worked_example judges a sample day by a policy — use it to answer "what happens if someone clocks in at …".{$applicable}
        - Editing a policy never changes days already recorded.
        - Presets: {$presets}.{$manage}
        TXT;
    }

    public function tools(User $user): array
    {
        $policy = ['type' => 'STRING', 'description' => 'The policy, by name.'];
        $preset = ['type' => 'STRING', 'enum' => AttendancePolicyPresets::keys()];
        $settings = array_map(fn (array $setting): array => $setting[1], self::SETTINGS);
        $settings['grace_from_schedule'] = ['type' => 'BOOLEAN', 'description' => "Use each schedule's own grace."];
        $settings['overtime_after_required_hours'] = ['type' => 'BOOLEAN', 'description' => "Daily overtime after the day's required hours."];

        $tools = [
            [
                'name' => 'find_attendance_policies',
                'description' => 'List the attendance policies: the company default, the preset each came from, its leading rules, and where it is named.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Part of the name.'],
                        'archived' => ['type' => 'BOOLEAN', 'description' => 'List archived policies instead.'],
                    ],
                ],
            ],
            [
                'name' => 'get_attendance_policy',
                'description' => 'Explain one attendance policy group by group, where it is named and how many people it judges today — or a preset, or the built-in rules.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'policy' => $policy,
                        'preset' => $preset,
                        'built_in' => ['type' => 'BOOLEAN', 'description' => 'Explain the built-in rules.'],
                    ],
                ],
            ],
            [
                'name' => 'get_worked_example',
                'description' => 'Judge a sample day by a policy (or a preset, or the built-in rules) with the real evaluator, writing nothing: status, late, short, overtime, night minutes and the rest.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'policy' => $policy,
                        'preset' => $preset,
                        'day' => ['type' => 'STRING', 'enum' => ['working', 'rest_day', 'holiday']],
                        'shift_start' => ['type' => 'STRING', 'description' => 'HH:MM, default 08:00.'],
                        'shift_end' => ['type' => 'STRING', 'description' => 'HH:MM, default 17:00.'],
                        'required_minutes' => ['type' => 'INTEGER', 'description' => 'Default 480.'],
                        'grace_minutes' => ['type' => 'INTEGER', 'description' => "The schedule's grace, default 0."],
                        'time_in' => ['type' => 'STRING', 'description' => 'HH:MM.'],
                        'time_out' => ['type' => 'STRING', 'description' => 'HH:MM; none if still clocked in.'],
                        'break_start' => ['type' => 'STRING', 'description' => 'HH:MM.'],
                        'break_end' => ['type' => 'STRING', 'description' => 'HH:MM.'],
                    ],
                    'required' => ['time_in'],
                ],
            ],
            [
                'name' => 'create_attendance_policy',
                'description' => 'Create an attendance policy from a preset (or the built-in rules), with any settings adjusted.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'preset' => $preset,
                        'description' => ['type' => 'STRING'],
                        ...$settings,
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'update_attendance_policy',
                'description' => "Change an attendance policy's name, description or settings. Only the fields given change.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'policy' => $policy,
                        'new_name' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                        ...$settings,
                    ],
                    'required' => ['policy'],
                ],
            ],
            [
                'name' => 'set_default_attendance_policy',
                'description' => 'Make a policy the company default — what judges anyone nothing more specific names a policy for — or clear the default.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'policy' => $policy,
                        'clear' => ['type' => 'BOOLEAN', 'description' => 'Clear the company default; the built-in rules apply instead.'],
                    ],
                ],
            ],
            [
                'name' => 'archive_attendance_policy',
                'description' => 'Archive an attendance policy. It stops being the default; schedules, departments and assignments that name it keep it.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['policy' => $policy],
                    'required' => ['policy'],
                ],
            ],
            [
                'name' => 'restore_attendance_policy',
                'description' => 'Restore an archived attendance policy.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['policy' => ['type' => 'STRING', 'description' => 'The archived policy, by name.']],
                    'required' => ['policy'],
                ],
            ],
        ];

        if ($this->allows($user, 'employees.view')) {
            array_splice($tools, 3, 0, [[
                'name' => 'get_applicable_policy',
                'description' => 'Which attendance policy judges a person on a date, and why.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Name or employee number.'],
                        'date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD; today when not given.'],
                    ],
                    'required' => ['employee'],
                ],
            ]]);
        }

        return $this->permitted($user, $tools);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return [
            'attendance policy', 'attendance policies', 'attendance rules', 'overtime rule', 'overtime rules',
            'overtime policy', 'grace period', 'night differential', 'night diff', 'undertime rule',
            'undertime rules', 'rounding', 'half day rule', 'tardiness rule', 'tardiness policy',
        ];
    }

    /**
     * The policies in a few lines: which is the default, what each leads with,
     * and how the one that applies is chosen.
     */
    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('setup.attendance-policies.view') || ! app(Tenancy::class)->check()) {
            return null;
        }

        $policies = AttendancePolicy::query()->orderByDesc('is_default')->orderBy('name')->limit(8)->get();

        return ContextSection::of('Attendance policies', [
            $policies->isEmpty()
                ? 'No attendance policies are set up: every day is judged by the built-in rules — '.$this->headline(AttendancePolicySettings::fallback()).'.'
                : null,
            ...$policies->map(fn (AttendancePolicy $p): string => $p->name.($p->is_default ? ' (company default)' : '').': '.$this->headline($p->settings()).'.')->all(),
            $policies->isNotEmpty() && $policies->where('is_default', true)->isEmpty()
                ? 'No company default: anyone nothing more specific names a policy for is judged by the built-in rules.'
                : null,
            'Which applies: the assignment’s policy, else the schedule’s, the department’s, the work location’s, the company default, else the built-in rules. Days already recorded keep the policy they were judged by.',
        ]);
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        if ($tool === 'set_default_attendance_policy') {
            $current = AttendancePolicy::query()->where('is_default', true)->first();
            $reached = $current !== null ? $this->coverage()->judgedBy($current->id) : $this->coverage()->judgedBy(null);

            return 'The company default judges the '.$this->people($reached).' nothing more specific names a policy for today'
                .($current !== null ? " (now {$current->name})" : ' (now the built-in rules)').'; days already recorded keep their rules.';
        }

        if (! in_array($tool, ['update_attendance_policy', 'archive_attendance_policy'], true)) {
            return null;
        }

        [$policy] = $this->locate((string) ($args['policy'] ?? ''));

        if ($policy === null) {
            return null;
        }

        $judged = $this->coverage()->judgedBy($policy->id);

        return $tool === 'archive_attendance_policy'
            ? "It judges {$this->people($judged)} today.".($policy->is_default ? ' It is the company default: the people it judges only as the default move to the built-in rules.' : ' What names it keeps it.')
            : "It judges {$this->people($judged)} today; their days from now on follow the change, and days already recorded keep their rules.";
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findPolicies(User $user, array $args): ToolResult
    {
        $archived = ($args['archived'] ?? false) === true;
        $needle = trim((string) ($args['query'] ?? ''));
        $like = AttendancePolicy::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $policies = AttendancePolicy::query()
            ->when($archived, fn (Builder $q) => $q->onlyTrashed())
            ->when($needle !== '', fn (Builder $q) => $q->where('name', $like, '%'.addcslashes($needle, '%_\\').'%'))
            ->withCount(['schedules', 'departments', 'assignments'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();

        $cards = $policies->map(fn (AttendancePolicy $p): array => $this->card(
            kind: 'find',
            tone: 'neutral',
            badge: $archived ? 'Archived' : ($p->is_default ? 'Company default' : ($p->preset()['name'] ?? 'Custom')),
            title: $p->name,
            subtitle: $this->headline($p->settings()),
            meta: [$this->namedBy($p)],
            id: $p->hashid,
        ))->all();

        return ToolResult::found($archived ? 'Searched archived attendance policies' : 'Listed attendance policies', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getPolicy(User $user, array $args): ToolResult
    {
        [$settings, $name, $policy, $error] = $this->subject($args);

        if ($settings === null) {
            return ToolResult::error('Looked up the policy', $error);
        }

        $meta = PolicyDescription::lines($settings);

        if ($policy !== null) {
            $policy->loadCount(['schedules', 'departments', 'assignments']);
            $meta[] = $this->namedBy($policy, spelled: true);
            $meta[] = 'Judges '.$this->people($this->coverage()->judgedBy($policy->id)).' today';
        } elseif ($name === self::BUILT_IN) {
            $meta[] = 'Judges '.$this->people($this->coverage()->judgedBy(null)).' today';
        }

        return ToolResult::found("Read {$name}", null, [$this->card(
            kind: 'insight',
            tone: 'info',
            badge: match (true) {
                $policy?->is_default === true => 'Company default',
                $policy !== null => $policy->preset()['name'] ?? 'Custom',
                default => 'Preset',
            },
            title: $name,
            subtitle: $policy !== null && filled($policy->description) ? Str::limit((string) $policy->description, 200) : $this->headline($settings),
            meta: $meta,
            id: $policy?->hashid,
        )]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function workedExample(User $user, array $args): ToolResult
    {
        [$settings, $name, , $error] = $this->subject($args);

        if ($settings === null) {
            return ToolResult::error('Worked the example', $error);
        }

        $clock = fn (string $key, ?string $default = null): ?string => $this->clock($args[$key] ?? null) ?? $default;

        $sample = [
            'day' => (string) ($args['day'] ?? 'working'),
            'shift_start' => $clock('shift_start', '08:00'),
            'shift_end' => $clock('shift_end', '17:00'),
            'required_minutes' => (int) ($args['required_minutes'] ?? 480),
            'grace_minutes' => (int) ($args['grace_minutes'] ?? 0),
            'time_in' => $clock('time_in'),
            'time_out' => $clock('time_out'),
            'break_start' => $clock('break_start'),
            'break_end' => $clock('break_end'),
        ];

        $sampleRules = Arr::where((new AttendancePolicyPreviewRequest)->rules(), fn (mixed $rule, string $key): bool => str_starts_with($key, 'sample'));

        if (($problem = $this->invalid(['sample' => $sample], $sampleRules, [], $this->sampleAttributes())) !== null) {
            return ToolResult::error('Worked the example', $problem);
        }

        $result = WorkedExample::evaluate($settings, $sample);
        $minutes = fn (string $key): string => PolicyDescription::duration((int) ($result[$key] ?? 0));

        $shift = $sample['day'] === 'rest_day' ? 'a rest day' : "a {$sample['shift_start']}–{$sample['shift_end']} shift".($sample['day'] === 'holiday' ? ' on a holiday' : '');
        $punches = "in {$sample['time_in']}".($sample['time_out'] ? ", out {$sample['time_out']}" : ', not yet out')
            .($sample['break_start'] ? ", break {$sample['break_start']}–{$sample['break_end']}" : '');

        return ToolResult::found("Worked an example under {$name}", null, [$this->card(
            kind: 'insight',
            tone: 'info',
            badge: 'Worked example',
            title: Str::headline((string) $result['status']),
            subtitle: "Under {$name}: {$shift}, {$punches}",
            meta: [
                $result['flags'] !== [] ? 'Flags: '.implode(', ', array_map(fn (string $f): string => str_replace('_', ' ', $f), $result['flags'])) : null,
                'Worked '.$minutes('worked_minutes').' (regular '.$minutes('regular_minutes').', overtime '.$minutes('overtime_minutes').', of it approved '.$minutes('approved_overtime_minutes').')',
                'Late '.$minutes('late_minutes').((int) $result['excused_late_minutes'] > 0 ? ' after '.$minutes('excused_late_minutes').' of grace' : ''),
                'Short '.$minutes('undertime_minutes'),
                'Break '.$minutes('break_minutes'),
                (int) $result['night_minutes'] > 0 ? 'Night '.$minutes('night_minutes') : null,
                (int) $result['rest_day_minutes'] > 0 ? 'Rest-day '.$minutes('rest_day_minutes') : null,
                (int) $result['holiday_minutes'] > 0 ? 'Holiday '.$minutes('holiday_minutes') : null,
                'A sample only: nothing was recorded.',
            ],
        )]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function applicablePolicy(User $user, array $args): ToolResult
    {
        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if ($employee === null) {
            return ToolResult::error('Looked up the employee', $error);
        }

        $date = filled($args['date'] ?? null) ? $this->isoDate($args['date']) : OrganizationClock::today();

        if ($date === null) {
            return ToolResult::error('Looked up the policy', 'Give the date as YYYY-MM-DD.');
        }

        $shift = (new ShiftResolver)->for($employee, $date);
        $resolved = (new PolicyResolver)->for($employee, $shift);

        $why = self::SOURCES[$resolved->source] ?? $resolved->source;

        if ($resolved->source === 'schedule' && $shift->scheduleName !== null) {
            $why .= " ({$shift->scheduleName})";
        } elseif ($resolved->source === 'department' && $employee->department_id !== null) {
            $why .= ' ('.(Department::withTrashed()->whereKey($employee->department_id)->value('name') ?? 'unknown').')';
        }

        return ToolResult::found("Read {$employee->full_name}'s attendance policy", null, [$this->card(
            kind: 'insight',
            tone: 'info',
            badge: 'Attendance policy',
            title: $resolved->name ?? self::BUILT_IN,
            subtitle: $employee->full_name.' on '.CarbonImmutable::parse($date)->format('D, M j, Y').': '.$why,
            meta: PolicyDescription::headline($resolved->settings),
        )]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function createPolicy(User $user, array $args): ToolResult
    {
        $preset = filled($args['preset'] ?? null) ? (string) $args['preset'] : null;
        $base = $preset !== null ? AttendancePolicyPresets::settings($preset) : AttendancePolicySettings::fallback();

        [$settings, $error] = $this->adjusted($base->toArray(), $args);

        if ($error !== null) {
            return ToolResult::error('Created the policy', $error);
        }

        $data = [
            'name' => trim((string) ($args['name'] ?? '')),
            'description' => filled($args['description'] ?? null) ? trim((string) $args['description']) : null,
            'preset_key' => $preset,
            'settings' => $settings,
        ];

        if (($problem = $this->invalidPolicy($data, null) ?? $this->nameTaken($data['name'])) !== null) {
            return ToolResult::error('Created the policy', $problem);
        }

        $policy = $this->workflow->create([
            ...$data,
            'settings' => AttendancePolicySettings::fromArray($settings)->toArray(),
            'settings_version' => AttendancePolicySettings::VERSION,
        ], ' via assistant');

        return ToolResult::ok(
            "Created {$policy->name}",
            'It judges nobody until it is named on a schedule, department or assignment, or made the company default.',
            $this->policyCard($policy, 'add', 'positive', 'Created'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updatePolicy(User $user, array $args): ToolResult
    {
        [$policy, $error] = $this->locate((string) ($args['policy'] ?? ''));

        if ($policy === null) {
            return ToolResult::error('Looked up the policy', $error);
        }

        $before = $policy->settings();
        [$settings, $error] = $this->adjusted($before->toArray(), $args);

        if ($error !== null) {
            return ToolResult::error('Updated the policy', $error);
        }

        $changes = [];

        if (filled($args['new_name'] ?? null)) {
            $changes['name'] = trim((string) $args['new_name']);
        }

        if (filled($args['description'] ?? null)) {
            $changes['description'] = trim((string) $args['description']);
        }

        $merged = [
            'name' => $policy->name,
            'description' => $policy->description,
            'preset_key' => $policy->preset_key,
            ...$changes,
            'settings' => $settings,
        ];

        // Validated as sent, before it is read back: the reader clamps, and a
        // value out of range must be refused, not quietly made the nearest one.
        if (($problem = $this->invalidPolicy($merged, $policy) ?? $this->nameTaken($merged['name'], $policy)) !== null) {
            return ToolResult::error('Updated the policy', $problem);
        }

        $after = AttendancePolicySettings::fromArray($settings);
        $changed = $this->changedGroups($before, $after);

        if ($changes === [] && $after->toArray() === $before->toArray()) {
            return ToolResult::error('Updated the policy', 'Say what to change: its name, description or a setting — or that setting already has that value.');
        }

        $this->workflow->update($policy, [
            ...$changes,
            'settings' => $after->toArray(),
            'settings_version' => AttendancePolicySettings::VERSION,
        ], ' via assistant');

        // The reply is written from the card, so the card leads with what
        // changed rather than with the policy's usual headline.
        $card = $this->policyCard($policy, 'edit', 'info', 'Updated');
        $card['subtitle'] = $changed !== [] ? implode('; ', $changed) : $card['subtitle'];

        return ToolResult::ok(
            "Updated {$policy->name}",
            'Days already recorded keep the rules they were judged by; HR can re-apply them from the attendance board.',
            $card,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setDefault(User $user, array $args): ToolResult
    {
        if (($args['clear'] ?? false) === true) {
            $current = AttendancePolicy::query()->where('is_default', true)->first();

            if ($current === null) {
                return ToolResult::error('Cleared the default policy', 'There is no company default to clear — the built-in rules already apply.');
            }

            $this->workflow->setDefault($current, false, ' via assistant');

            return ToolResult::ok(
                'Cleared the company default policy',
                'The built-in rules apply to anyone nothing more specific names a policy for.',
                $this->policyCard($current, 'edit', 'warning', 'Default cleared'),
            );
        }

        [$policy, $error] = $this->locate((string) ($args['policy'] ?? ''));

        if ($policy === null) {
            return ToolResult::error('Looked up the policy', $error);
        }

        if ($policy->is_default) {
            return ToolResult::error('Set the company default', "{$policy->name} is already the company default.");
        }

        $this->workflow->setDefault($policy, true, ' via assistant');

        return ToolResult::ok("Made {$policy->name} the company default", null, $this->policyCard($policy, 'edit', 'info', 'Company default'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archivePolicy(User $user, array $args): ToolResult
    {
        [$policy, $error] = $this->locate((string) ($args['policy'] ?? ''));

        if ($policy === null) {
            return ToolResult::error('Looked up the policy', $error);
        }

        $card = $this->policyCard($policy, 'archive', 'warning', 'Archived');
        $wasDefault = (bool) $policy->is_default;

        $this->workflow->archive($policy, ' via assistant');

        return ToolResult::ok(
            "Archived {$policy->name}",
            ($wasDefault ? 'It is no longer the company default. ' : '').'Schedules, departments and assignments that name it keep it; it can be restored.',
            $card,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function restorePolicy(User $user, array $args): ToolResult
    {
        [$policy, $error] = $this->locate((string) ($args['policy'] ?? ''), archived: true);

        if ($policy === null) {
            return ToolResult::error('Looked up the archived policy', $error);
        }

        try {
            $this->workflow->restore($policy, ' via assistant');
        } catch (AttendancePolicyException $e) {
            return ToolResult::error('Restored the policy', $e->getMessage());
        }

        return ToolResult::ok("Restored {$policy->name}", 'It is not the company default; make it one if that is wanted.', $this->policyCard($policy, 'start', 'positive', 'Restored'));
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    /**
     * A settings document with the arguments' settings laid over it — or why an
     * argument cannot be one.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $args
     * @return array{0: array<string, mixed>, 1: string|null}
     */
    private function adjusted(array $settings, array $args): array
    {
        foreach (self::SETTINGS as $argument => [$path]) {
            if (! array_key_exists($argument, $args) || $args[$argument] === null) {
                continue;
            }

            $value = $args[$argument];

            if (in_array($argument, ['night_start', 'night_end'], true)) {
                $value = $this->clock($value);
            } elseif (in_array($argument, self::ZERO_IS_OFF, true) && (int) $value === 0) {
                $value = null;
            }

            data_set($settings, $path, $value);
        }

        if (($args['grace_from_schedule'] ?? false) === true) {
            if (isset($args['grace_minutes'])) {
                return [$settings, 'Either give grace_minutes or use each schedule’s grace, not both.'];
            }

            data_set($settings, 'lateness.grace_minutes', null);
        }

        if (($args['overtime_after_required_hours'] ?? false) === true) {
            if (isset($args['overtime_daily_after_minutes'])) {
                return [$settings, 'Either give overtime_daily_after_minutes or start overtime after the day’s required hours, not both.'];
            }

            data_set($settings, 'overtime.daily_after_minutes', null);
        }

        return [$settings, null];
    }

    /**
     * A policy against the screen's own rules — the name unique among live
     * policies, every setting in range, and the thresholds that must agree —
     * with a problem named by the tool's own parameter.
     *
     * @param  array<string, mixed>  $data
     */
    private function invalidPolicy(array $data, ?AttendancePolicy $policy): ?string
    {
        $request = new AttendancePolicyRequest;
        $attributes = [];

        foreach (self::SETTINGS as $argument => [$path]) {
            $attributes["settings.{$path}"] = $argument;
        }

        return $this->invalid(
            $data,
            AttendancePolicyRequest::rulesFor($policy),
            $request->messages(),
            $attributes,
            fn (Validator $validator) => AttendancePolicyRequest::validateSettings($validator, (array) ($data['settings'] ?? [])),
        );
    }

    /**
     * The screen's refusal when another live policy has this name in any case.
     * Its rule compares exactly, so "office" could sit beside "Office" — and
     * neither could then be named here. Stricter than the screen, on purpose.
     */
    private function nameTaken(string $name, ?AttendancePolicy $except = null): ?string
    {
        $taken = AttendancePolicy::query()
            ->whereRaw('lower(name) = ?', [Str::lower(trim($name))])
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->id))
            ->exists();

        return $taken ? (new AttendancePolicyRequest)->messages()['name.unique'] : null;
    }

    /**
     * What changed, group by group: "Overtime: After 8h a day → After 8h a day,
     * needs approval".
     *
     * @return list<string>
     */
    private function changedGroups(AttendancePolicySettings $before, AttendancePolicySettings $after): array
    {
        $old = PolicyDescription::groups($before);
        $new = PolicyDescription::groups($after);
        $lines = [];

        foreach (PolicyDescription::GROUPS as $key => $label) {
            if ($old[$key] !== $new[$key]) {
                $lines[] = "{$label}: {$old[$key]} → {$new[$key]}";
            }
        }

        return $lines;
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * What a read is about: a policy by name, a preset, or the built-in rules.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: AttendancePolicySettings|null, 1: string, 2: AttendancePolicy|null, 3: string}
     */
    private function subject(array $args): array
    {
        if (filled($args['policy'] ?? null)) {
            [$policy, $error] = $this->locate((string) $args['policy']);

            return $policy === null
                ? [null, '', null, $error]
                : [$policy->settings(), $policy->name, $policy, ''];
        }

        if (filled($args['preset'] ?? null) && ($preset = AttendancePolicyPresets::find((string) $args['preset'])) !== null) {
            return [AttendancePolicyPresets::settings($preset['key']), $preset['name'].' (preset)', null, ''];
        }

        if (($args['built_in'] ?? false) === true) {
            return [AttendancePolicySettings::fallback(), self::BUILT_IN, null, ''];
        }

        // Nothing named: the company default, or the built-in rules without one.
        $default = AttendancePolicy::query()->where('is_default', true)->first();

        return $default !== null
            ? [$default->settings(), $default->name, $default, '']
            : [AttendancePolicySettings::fallback(), self::BUILT_IN, null, ''];
    }

    /**
     * Exactly one policy by name — an exact name first, then a partial name
     * only one policy has.
     *
     * @return array{0: AttendancePolicy|null, 1: string}
     */
    private function locate(string $needle, bool $archived = false): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [null, 'Say which policy.'];
        }

        $base = fn (): Builder => $archived ? AttendancePolicy::onlyTrashed() : AttendancePolicy::query();
        $matches = $base()->whereRaw('lower(name) = ?', [Str::lower($needle)])->limit(2)->get();

        if ($matches->isEmpty()) {
            $like = AttendancePolicy::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $matches = $base()->where('name', $like, '%'.addcslashes($needle, '%_\\').'%')->orderBy('name')->limit(6)->get();
        }

        return match (true) {
            $matches->isEmpty() => [null, ($archived ? 'No archived policy' : 'No attendance policy').' matches “'.Str::limit($needle, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one policy matches “'.Str::limit($needle, 60).'”: '.$matches->pluck('name')->implode(', ').'.'],
        };
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    private function headline(AttendancePolicySettings $settings): string
    {
        return implode(' · ', PolicyDescription::headline($settings));
    }

    /**
     * Where a policy is named — counts, or (spelled) the schedules and
     * departments by name.
     */
    private function namedBy(AttendancePolicy $policy, bool $spelled = false): string
    {
        $parts = [];

        if ($spelled) {
            $schedules = WorkSchedule::withTrashed()->where('attendance_policy_id', $policy->id)->orderBy('name')->limit(8)->pluck('name');
            $departments = Department::withTrashed()->where('attendance_policy_id', $policy->id)->orderBy('name')->limit(8)->pluck('name');

            $parts[] = $schedules->isNotEmpty() ? 'schedules '.$schedules->implode(', ') : null;
            $parts[] = $departments->isNotEmpty() ? 'departments '.$departments->implode(', ') : null;
        } else {
            $parts[] = $policy->schedules_count ? $policy->schedules_count.' '.Str::plural('schedule', (int) $policy->schedules_count) : null;
            $parts[] = $policy->departments_count ? $policy->departments_count.' '.Str::plural('department', (int) $policy->departments_count) : null;
        }

        $parts[] = $policy->assignments_count ? $policy->assignments_count.' '.Str::plural('assignment', (int) $policy->assignments_count) : null;
        $parts = array_values(array_filter($parts));

        return $parts === [] ? 'Not named anywhere'.($policy->is_default ? ' (applies as the default)' : '') : 'Named on '.implode('; ', $parts);
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

    /**
     * @return array<string, string>
     */
    private function sampleAttributes(): array
    {
        return [
            'sample.shift_start' => 'shift_start',
            'sample.shift_end' => 'shift_end',
            'sample.required_minutes' => 'required_minutes',
            'sample.grace_minutes' => 'grace_minutes',
            'sample.time_in' => 'time_in',
            'sample.time_out' => 'time_out',
            'sample.break_start' => 'break_start',
            'sample.break_end' => 'break_end',
        ];
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
    private function policyCard(AttendancePolicy $policy, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $policy->name,
            subtitle: $this->headline($policy->settings()),
            meta: [
                $policy->is_default ? 'Company default' : null,
                $policy->preset()['name'] ?? null,
            ],
            id: $policy->hashid,
        );
    }
}
