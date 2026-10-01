<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Setup\LeaveTypeRequest;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\Attendance\DayCloser;
use App\Support\OrganizationClock;
use App\Support\Setup\LeaveTypeException;
use App\Support\Setup\LeaveTypeWorkflow;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Leave Types capability: the kinds of leave the company grants — each with a
 * code, a default yearly entitlement, and whether it is paid, can be taken as a
 * half day, and needs approval.
 *
 * **Reading** answers "what leave do we offer?", "how many days of vacation
 * leave does everyone get?", "how much sick leave has been taken this year?".
 * **Doing** creates, edits, archives and restores types through
 * {@see LeaveTypeWorkflow} — the Leave Types screen's own path — against the
 * screen's own rules ({@see LeaveTypeRequest::rulesFor()}: a per-tenant unique
 * code, upper-cased).
 *
 * Filing, approving and entitlements for a person are the Leave capability's;
 * this is the catalogue. Permanent deletion stays on the screen.
 *
 * Editing a type and archiving one wait for the user's Confirm (ADR 0049), and
 * the card says whom it reaches: a type's default entitlement is the balance of
 * everybody without one of their own for the year, read live. Everything needs
 * `setup.leave-types.view`; changes `setup.leave-types.manage`.
 */
class LeaveTypesModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    /** How many types a list returns. */
    private const MAX_RESULTS = 30;

    /**
     * The policy flags, as the tools name them.
     *
     * @var array<string, string>
     */
    private const FLAGS = [
        'paid' => 'is_paid',
        'half_day' => 'allow_half_day',
        'requires_approval' => 'requires_approval',
    ];

    public function __construct(private readonly LeaveTypeWorkflow $workflow) {}

    public function key(): string
    {
        return 'leave-types';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('setup.leave-types.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_leave_types' => 'findTypes',
            'get_leave_type' => 'getType',
            'create_leave_type' => 'createType',
            'update_leave_type' => 'updateType',
            'archive_leave_type' => 'archiveType',
            'restore_leave_type' => 'restoreType',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_leave_types' => 'setup.leave-types.view',
            'get_leave_type' => 'setup.leave-types.view',
            'create_leave_type' => 'setup.leave-types.manage',
            'update_leave_type' => 'setup.leave-types.manage',
            'archive_leave_type' => 'setup.leave-types.manage',
            'restore_leave_type' => 'setup.leave-types.manage',
        ];
    }

    protected function confirmTools(): array
    {
        // A type's entitlement and flags reach everybody who takes it.
        return ['update_leave_type', 'archive_leave_type'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'setup.leave-types.view';

        if ($user->cannot($permission)) {
            return $this->denied($permission === 'setup.leave-types.manage' ? 'change leave types' : 'view leave types');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $manage = $this->allows($user, 'setup.leave-types.manage')
            ? <<<'TXT'

            - create_leave_type makes one: name, a short unique code (stored upper-case), default days a year, and whether it is paid, can be a half day, and needs approval.
            - update_leave_type changes one (inactive types cannot be filed); archive_leave_type archives one. Both wait for the user's confirmation. restore_leave_type brings one back.
            - Permanent deletion is done on the Leave Types screen (/setup/leave-types) — say so if asked.
            TXT
            : '';

        return <<<TXT
        LEAVE TYPES — the kinds of leave the company grants, each with a code, a default yearly entitlement (for anyone without one of their own), and whether it is paid, can be a half day, and needs approval.
        - find_leave_types lists them; get_leave_type reads one with this year's use. A person's balance or filing leave is the leave capability, not this.{$manage}
        TXT;
    }

    public function tools(User $user): array
    {
        $type = ['type' => 'STRING', 'description' => 'The leave type, by name or code.'];
        $settings = [
            'code' => ['type' => 'STRING', 'description' => 'A short unique code, e.g. VL.'],
            'default_days' => ['type' => 'NUMBER', 'description' => 'Days a year for anyone without their own entitlement.'],
            'paid' => ['type' => 'BOOLEAN'],
            'half_day' => ['type' => 'BOOLEAN', 'description' => 'Can be taken as a half day.'],
            'requires_approval' => ['type' => 'BOOLEAN'],
            'description' => ['type' => 'STRING'],
        ];

        return $this->permitted($user, [
            [
                'name' => 'find_leave_types',
                'description' => 'List leave types with their code, days and flags.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Part of the name or code.'],
                        'archived' => ['type' => 'BOOLEAN'],
                    ],
                ],
            ],
            [
                'name' => 'get_leave_type',
                'description' => "Read one leave type and this year's use of it.",
                'parameters' => ['type' => 'OBJECT', 'properties' => ['leave_type' => $type], 'required' => ['leave_type']],
            ],
            [
                'name' => 'create_leave_type',
                'description' => 'Create a leave type.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['name' => ['type' => 'STRING'], ...$settings],
                    'required' => ['name', 'code', 'default_days'],
                ],
            ],
            [
                'name' => 'update_leave_type',
                'description' => "Change a leave type's name, code, default days, flags, description or active flag. Only the fields given change.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'leave_type' => $type,
                        'new_name' => ['type' => 'STRING'],
                        ...$settings,
                        'active' => ['type' => 'BOOLEAN', 'description' => 'Inactive types cannot be filed.'],
                    ],
                    'required' => ['leave_type'],
                ],
            ],
            [
                'name' => 'archive_leave_type',
                'description' => 'Archive a leave type. Filed requests keep it; it can be restored.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['leave_type' => $type], 'required' => ['leave_type']],
            ],
            [
                'name' => 'restore_leave_type',
                'description' => 'Restore an archived leave type.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['leave_type' => $type], 'required' => ['leave_type']],
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
            'leave types', 'leave type', 'kinds of leave', 'types of leave', 'leave policy', 'leave policies',
            'leave entitlement', 'leave entitlements', 'leave credits', 'uri ng leave',
        ];
    }

    /**
     * The catalogue in a line or two.
     */
    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('setup.leave-types.view') || ! app(Tenancy::class)->check()) {
            return null;
        }

        $types = LeaveType::query()->orderByDesc('is_active')->orderBy('name')->get();

        return ContextSection::of('Leave types', [
            $types->isEmpty()
                ? 'No leave types have been set up.'
                : 'Leave types (default days a year): '.$types->map(fn (LeaveType $t): string => "{$t->name} ({$t->code}, {$this->days($t->default_days)}; ".implode(', ', $this->flagWords($t)).')')->implode('; ').'.',
        ], 'A person with an entitlement of their own for the year has that instead of the default.');
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        if (! in_array($tool, ['update_leave_type', 'archive_leave_type'], true)) {
            return null;
        }

        [$type] = $this->locate((string) ($args['leave_type'] ?? ''));

        if ($type === null) {
            return null;
        }

        $pending = $type->requests()->where('status', 'pending')->count();
        $onDefault = $this->onDefault($type);

        return $tool === 'archive_leave_type'
            ? "It leaves the list people file from. {$pending} pending ".Str::plural('request', $pending).' under it stay open; filed requests keep it.'
            : "Its default entitlement is this year's balance for {$this->people($onDefault)} without one of their own, read live; {$pending} ".Str::plural('request', $pending).' under it '.($pending === 1 ? 'is' : 'are').' pending.';
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findTypes(User $user, array $args): ToolResult
    {
        $archived = ($args['archived'] ?? false) === true;

        $types = LeaveType::query()
            ->when($archived, fn (Builder $q) => $q->onlyTrashed())
            ->search(addcslashes((string) ($args['query'] ?? ''), '%_\\'))
            ->withCount('requests')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();

        $cards = $types->map(fn (LeaveType $t): array => $this->typeCard($t, 'find', 'neutral', $archived ? 'Archived' : ($t->is_active ? $t->code : 'Inactive')))->all();

        return ToolResult::found($archived ? 'Searched archived leave types' : 'Listed leave types', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getType(User $user, array $args): ToolResult
    {
        [$type, $error] = $this->locate((string) ($args['leave_type'] ?? ''));

        if ($type === null) {
            return ToolResult::error('Looked up the leave type', $error);
        }

        $year = (int) substr(OrganizationClock::today(), 0, 4);
        $thisYear = $type->requests()->whereYear('start_date', $year);
        $approved = (clone $thisYear)->where('status', 'approved');
        $taken = (float) (clone $approved)->sum('days');
        $own = LeaveBalance::query()->where('leave_type_id', $type->id)->where('year', $year)->count();

        $card = $this->typeCard($type, 'insight', 'info', $type->is_active ? $type->code : 'Inactive');
        $card['meta'] = array_values(array_filter([
            'Default: '.$this->days($type->default_days).' a year',
            Str::ucfirst(implode(', ', $this->flagWords($type))),
            filled($type->description) ? 'About: '.Str::limit((string) $type->description, 200) : null,
            "{$year}: ".(clone $approved)->count().' approved '.Str::plural('request', (clone $approved)->count()).' ('.$this->days($taken).' taken), '
                .(clone $thisYear)->where('status', 'pending')->count().' pending',
            "{$this->people($own)} ".($own === 1 ? 'has' : 'have')." an entitlement of their own for {$year}; {$this->people($this->onDefault($type))} ".($this->onDefault($type) === 1 ? 'is' : 'are').' on the default',
        ]));

        return ToolResult::found("Read {$type->name}", null, [$card]);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function createType(User $user, array $args): ToolResult
    {
        $data = [
            'name' => trim((string) ($args['name'] ?? '')),
            'code' => LeaveTypeRequest::normaliseCode((string) ($args['code'] ?? '')),
            'description' => filled($args['description'] ?? null) ? trim((string) $args['description']) : null,
            'color' => $this->workflow->nextColor(),
            'default_days' => $args['default_days'] ?? null,
            'is_paid' => ($args['paid'] ?? true) === true,
            'allow_half_day' => ($args['half_day'] ?? true) === true,
            'requires_approval' => ($args['requires_approval'] ?? true) === true,
            'is_active' => true,
        ];

        if (($problem = $this->invalid($data, LeaveTypeRequest::rulesFor(null)) ?? $this->nameTaken($data['name'])) !== null) {
            return ToolResult::error('Created the leave type', $problem);
        }

        $type = $this->workflow->create($data, ' via assistant');

        return ToolResult::ok(
            "Created {$type->name}",
            "{$this->days($type->default_days)} a year for anyone without an entitlement of their own.",
            $this->typeCard($type, 'add', 'positive', 'Created'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateType(User $user, array $args): ToolResult
    {
        [$type, $error] = $this->locate((string) ($args['leave_type'] ?? ''));

        if ($type === null) {
            return ToolResult::error('Looked up the leave type', $error);
        }

        $changes = [];

        if (filled($args['new_name'] ?? null)) {
            $changes['name'] = trim((string) $args['new_name']);
        }

        if (filled($args['code'] ?? null)) {
            $changes['code'] = LeaveTypeRequest::normaliseCode((string) $args['code']);
        }

        if (isset($args['default_days'])) {
            $changes['default_days'] = $args['default_days'];
        }

        if (filled($args['description'] ?? null)) {
            $changes['description'] = trim((string) $args['description']);
        }

        foreach ([...self::FLAGS, 'active' => 'is_active'] as $argument => $column) {
            if (is_bool($args[$argument] ?? null)) {
                $changes[$column] = $args[$argument];
            }
        }

        if ($changes === []) {
            return ToolResult::error('Updated the leave type', 'Say what to change: its name, code, default days, whether it is paid, allows a half day or needs approval, its description, or whether it is active.');
        }

        $merged = [
            ...Arr::only($type->getAttributes(), ['name', 'code', 'description', 'color', 'default_days']),
            ...Arr::map(Arr::only($type->getAttributes(), ['is_paid', 'allow_half_day', 'requires_approval', 'is_active']), fn (mixed $v): bool => (bool) $v),
            ...$changes,
        ];

        $problem = $this->invalid($merged, LeaveTypeRequest::rulesFor($type))
            ?? (isset($changes['name']) ? $this->nameTaken($changes['name'], $type) : null);

        if ($problem !== null) {
            return ToolResult::error('Updated the leave type', $problem);
        }

        $before = $type->replicate();
        $this->workflow->update($type, $changes, ' via assistant');

        return ToolResult::ok(
            "Updated {$type->name}",
            null,
            $this->typeCard($type, 'edit', 'info', 'Updated', $this->changed($before, $type)),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archiveType(User $user, array $args): ToolResult
    {
        [$type, $error] = $this->locate((string) ($args['leave_type'] ?? ''));

        if ($type === null) {
            return ToolResult::error('Looked up the leave type', $error);
        }

        $card = $this->typeCard($type, 'archive', 'warning', 'Archived');

        $this->workflow->archive($type, ' via assistant');

        return ToolResult::ok("Archived {$type->name}", 'Filed requests keep it; it can be restored.', $card);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function restoreType(User $user, array $args): ToolResult
    {
        [$type, $error] = $this->locate((string) ($args['leave_type'] ?? ''), archived: true);

        if ($type === null) {
            return ToolResult::error('Looked up the archived leave type', $error);
        }

        try {
            $this->workflow->restore($type, ' via assistant');
        } catch (LeaveTypeException $e) {
            return ToolResult::error('Restored the leave type', $e->getMessage());
        }

        return ToolResult::ok("Restored {$type->name}", null, $this->typeCard($type, 'start', 'positive', 'Restored'));
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one leave type by name or code — exact first, then a partial one
     * only one type matches.
     *
     * @return array{0: LeaveType|null, 1: string}
     */
    private function locate(string $needle, bool $archived = false): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [null, 'Say which leave type.'];
        }

        $base = fn (): Builder => $archived ? LeaveType::onlyTrashed() : LeaveType::query();

        $matches = $base()
            ->where(fn (Builder $q) => $q
                ->whereRaw('lower(name) = ?', [Str::lower($needle)])
                ->orWhereRaw('lower(code) = ?', [Str::lower($needle)]))
            ->limit(2)
            ->get();

        if ($matches->isEmpty()) {
            $matches = $base()->search(addcslashes($needle, '%_\\'))->orderBy('name')->limit(6)->get();
        }

        return match (true) {
            $matches->isEmpty() => [null, ($archived ? 'No archived leave type' : 'No leave type').' matches “'.Str::limit($needle, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one leave type matches “'.Str::limit($needle, 60).'”: '.$matches->map(fn (LeaveType $t): string => "{$t->name} ({$t->code})")->implode(', ').'.'],
        };
    }

    /**
     * Refuse a name another live type has in any case. The screen does not
     * insist; here it keeps two types from answering to one name.
     */
    private function nameTaken(string $name, ?LeaveType $except = null): ?string
    {
        $taken = LeaveType::query()
            ->whereRaw('lower(name) = ?', [Str::lower(trim($name))])
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->id))
            ->exists();

        return $taken ? "There is already a leave type called “{$name}”." : null;
    }

    /**
     * How many of the people whose days are counted have no entitlement of
     * their own for this type this year, so its default is theirs.
     */
    private function onDefault(LeaveType $type): int
    {
        $year = (int) substr(OrganizationClock::today(), 0, 4);

        return Employee::query()
            ->whereIn('employment_status', DayCloser::WORKING_STATUSES)
            ->whereDoesntHave('leaveBalances', fn (Builder $q) => $q->where('leave_type_id', $type->id)->where('year', $year))
            ->count();
    }

    // ── Presentation ─────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function flagWords(LeaveType $type): array
    {
        return [
            $type->is_paid ? 'paid' : 'unpaid',
            $type->allow_half_day ? 'half days allowed' : 'whole days only',
            $type->requires_approval ? 'needs approval' : 'approved on filing',
        ];
    }

    /**
     * What an edit changed, in words: "Default: 15 days → 18 days".
     *
     * @return list<string>
     */
    private function changed(LeaveType $before, LeaveType $after): array
    {
        $lines = [];

        foreach (['name' => 'Name', 'code' => 'Code', 'default_days' => 'Default', 'description' => 'Description'] as $column => $label) {
            $old = $column === 'default_days' ? $this->days($before->default_days) : (string) $before->{$column};
            $new = $column === 'default_days' ? $this->days($after->default_days) : (string) $after->{$column};

            if ($old !== $new) {
                $lines[] = "{$label}: ".Str::limit($old, 60).' → '.Str::limit($new, 60);
            }
        }

        foreach (['is_paid' => 'Paid', 'allow_half_day' => 'Half days', 'requires_approval' => 'Needs approval', 'is_active' => 'Active'] as $column => $label) {
            if ((bool) $before->{$column} !== (bool) $after->{$column}) {
                $lines[] = "{$label}: ".($after->{$column} ? 'yes' : 'no');
            }
        }

        return $lines;
    }

    private function days(mixed $days): string
    {
        $value = (float) $days;
        $text = rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');

        return $text.' '.Str::plural('day', $value == 1.0 ? 1 : 2);
    }

    private function people(int $count): string
    {
        return $count.' '.Str::plural('person', $count);
    }

    /**
     * @param  list<string>|null  $meta
     * @return array<string, mixed>
     */
    private function typeCard(LeaveType $type, string $kind, string $tone, string $badge, ?array $meta = null): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: "{$type->name} ({$type->code})",
            subtitle: $this->days($type->default_days).' a year · '.implode(', ', $this->flagWords($type)),
            meta: $meta ?? [isset($type->requests_count) ? $type->requests_count.' '.Str::plural('request', (int) $type->requests_count).' filed' : null],
            id: $type->hashid,
        );
    }
}
