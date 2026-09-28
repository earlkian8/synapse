<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\Setup\AwardTypeRequest;
use App\Models\AwardType;
use App\Models\User;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\ToolResult;
use App\Support\OrganizationClock;
use App\Support\Setup\AwardTypeWorkflow;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Award Types capability: the catalogue of recognitions the Awards module gives
 * out — "Employee of the Month", "Perfect Attendance" — each of which can be
 * retired from being given without losing the awards already given.
 *
 * **Reading** answers "what awards do we have?", "how often has Perfect
 * Attendance been given?". **Doing** creates, edits (and retires or
 * reactivates), archives and restores types through {@see AwardTypeWorkflow} —
 * the Award Types screen's own path — against the screen's own rules
 * ({@see AwardTypeRequest}).
 *
 * Giving an award, and who received one, are the Awards capability's; this
 * reads counts only, so a catalogue manager is told how much a type is used,
 * never whom it went to. Permanent deletion stays on the screen.
 *
 * Archiving waits for the user's Confirm (ADR 0049). Everything needs
 * `setup.award-types.view`; changes `setup.award-types.manage`.
 */
class AwardTypesModule extends Module implements ExplainsConsequences
{
    /** How many types a list returns. */
    private const MAX_RESULTS = 30;

    public function __construct(private readonly AwardTypeWorkflow $workflow) {}

    public function key(): string
    {
        return 'award-types';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('setup.award-types.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_award_types' => 'findTypes',
            'get_award_type' => 'getType',
            'create_award_type' => 'createType',
            'update_award_type' => 'updateType',
            'archive_award_type' => 'archiveType',
            'restore_award_type' => 'restoreType',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_award_types' => 'setup.award-types.view',
            'get_award_type' => 'setup.award-types.view',
            'create_award_type' => 'setup.award-types.manage',
            'update_award_type' => 'setup.award-types.manage',
            'archive_award_type' => 'setup.award-types.manage',
            'restore_award_type' => 'setup.award-types.manage',
        ];
    }

    protected function confirmTools(): array
    {
        return ['archive_award_type'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        $permission = $this->permissionMap()[$tool] ?? 'setup.award-types.view';

        if ($user->cannot($permission)) {
            return $this->denied($permission === 'setup.award-types.manage' ? 'change award types' : 'view award types');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $manage = $this->allows($user, 'setup.award-types.manage')
            ? <<<'TXT'

            - create_award_type / update_award_type set a type's name and description; active false retires it (it can no longer be given; awards already given keep it). archive_award_type waits for the user's confirmation; restore_award_type brings one back. Permanent deletion is done on the Award Types screen (/setup/award-types).
            TXT
            : '';

        return <<<TXT
        AWARD TYPES — the catalogue of recognitions the company gives out.
        - find_award_types lists them (archived ones on request); get_award_type reads one and how often it has been given. Giving an award is the awards capability, not this.{$manage}
        TXT;
    }

    public function tools(User $user): array
    {
        $type = ['type' => 'STRING', 'description' => 'The award type, by name.'];

        return $this->permitted($user, [
            [
                'name' => 'find_award_types',
                'description' => 'List award types, with how often each has been given.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['archived' => ['type' => 'BOOLEAN']]],
            ],
            [
                'name' => 'get_award_type',
                'description' => 'Read one award type and how often it has been given.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['award_type' => $type], 'required' => ['award_type']],
            ],
            [
                'name' => 'create_award_type',
                'description' => 'Create an award type.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['name' => ['type' => 'STRING'], 'description' => ['type' => 'STRING']],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'update_award_type',
                'description' => 'Rename an award type, change its description, or retire (active false) or reactivate it.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'award_type' => $type,
                        'new_name' => ['type' => 'STRING'],
                        'description' => ['type' => 'STRING'],
                        'active' => ['type' => 'BOOLEAN'],
                    ],
                    'required' => ['award_type'],
                ],
            ],
            [
                'name' => 'archive_award_type',
                'description' => 'Archive an award type. Awards already given keep it; it can be restored.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['award_type' => $type], 'required' => ['award_type']],
            ],
            [
                'name' => 'restore_award_type',
                'description' => 'Restore an archived award type.',
                'parameters' => ['type' => 'OBJECT', 'properties' => ['award_type' => $type], 'required' => ['award_type']],
            ],
        ]);
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        if ($tool !== 'archive_award_type') {
            return null;
        }

        [$type] = $this->locate((string) ($args['award_type'] ?? ''));

        if ($type === null) {
            return null;
        }

        $given = $type->awards()->count();

        return "It has been given {$given} ".Str::plural('time', $given).'; those awards keep it. It can no longer be given until it is restored.';
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findTypes(User $user, array $args): ToolResult
    {
        $archived = ($args['archived'] ?? false) === true;

        $cards = AwardType::query()
            ->when($archived, fn (Builder $q) => $q->onlyTrashed())
            ->withCount('awards')
            ->catalogueOrder()
            ->limit(self::MAX_RESULTS)
            ->get()
            ->map(fn (AwardType $t): array => $this->typeCard($t, 'find', 'neutral', $archived ? 'Archived' : ($t->is_active ? 'Active' : 'Retired')))
            ->all();

        return ToolResult::found($archived ? 'Searched archived award types' : 'Listed award types', count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getType(User $user, array $args): ToolResult
    {
        [$type, $error] = $this->locate((string) ($args['award_type'] ?? ''));

        if ($type === null) {
            return ToolResult::error('Looked up the award type', $error);
        }

        $year = (int) substr(OrganizationClock::today(), 0, 4);
        $given = $type->awards()->count();
        $thisYear = $type->awards()->whereYear('awarded_on', $year)->count();
        $last = $type->awards()->max('awarded_on');

        $card = $this->typeCard($type->loadCount('awards'), 'insight', $type->is_active ? 'info' : 'neutral', $type->is_active ? 'Active' : 'Retired');
        $card['meta'] = array_values(array_filter([
            "Given {$given} ".Str::plural('time', $given)." in all, {$thisYear} in {$year}",
            $last !== null ? 'Last given '.Str::before((string) $last, ' ') : 'Never given yet',
            $type->is_active ? null : 'Retired: it can no longer be given',
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
            'description' => filled($args['description'] ?? null) ? trim((string) $args['description']) : null,
            'is_active' => true,
        ];

        if (($problem = $this->invalid($data, (new AwardTypeRequest)->rules()) ?? $this->nameTaken($data['name'])) !== null) {
            return ToolResult::error('Created the award type', $problem);
        }

        $type = $this->workflow->create($data, ' via assistant');

        return ToolResult::ok("Created {$type->name}", null, $this->typeCard($type, 'add', 'positive', 'Created'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateType(User $user, array $args): ToolResult
    {
        [$type, $error] = $this->locate((string) ($args['award_type'] ?? ''));

        if ($type === null) {
            return ToolResult::error('Looked up the award type', $error);
        }

        $changes = [];

        if (filled($args['new_name'] ?? null)) {
            $changes['name'] = trim((string) $args['new_name']);
        }

        if (filled($args['description'] ?? null)) {
            $changes['description'] = trim((string) $args['description']);
        }

        if (is_bool($args['active'] ?? null)) {
            $changes['is_active'] = $args['active'];
        }

        if ($changes === []) {
            return ToolResult::error('Updated the award type', 'Say what to change: its name, description, or whether it can still be given.');
        }

        $merged = ['name' => $type->name, 'description' => $type->description, 'color' => $type->color, 'is_active' => (bool) $type->is_active, ...$changes];
        $problem = $this->invalid($merged, (new AwardTypeRequest)->rules())
            ?? (isset($changes['name']) ? $this->nameTaken($changes['name'], $type) : null);

        if ($problem !== null) {
            return ToolResult::error('Updated the award type', $problem);
        }

        $this->workflow->update($type, $changes, ' via assistant');

        $badge = match ($changes['is_active'] ?? null) {
            true => 'Reactivated',
            false => 'Retired',
            default => 'Updated',
        };

        return ToolResult::ok(
            "Updated {$type->name}",
            ($changes['is_active'] ?? null) === false ? 'It can no longer be given; awards already given keep it.' : null,
            $this->typeCard($type->loadCount('awards'), 'edit', 'info', $badge),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function archiveType(User $user, array $args): ToolResult
    {
        [$type, $error] = $this->locate((string) ($args['award_type'] ?? ''));

        if ($type === null) {
            return ToolResult::error('Looked up the award type', $error);
        }

        $card = $this->typeCard($type->loadCount('awards'), 'archive', 'warning', 'Archived');

        $this->workflow->archive($type, ' via assistant');

        return ToolResult::ok("Archived {$type->name}", 'Awards already given keep it; it can be restored.', $card);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function restoreType(User $user, array $args): ToolResult
    {
        [$type, $error] = $this->locate((string) ($args['award_type'] ?? ''), archived: true);

        if ($type === null) {
            return ToolResult::error('Looked up the archived award type', $error);
        }

        if (($problem = $this->nameTaken($type->name)) !== null) {
            return ToolResult::error('Restored the award type', $problem.' Rename one of them first.');
        }

        $this->workflow->restore($type, ' via assistant');

        return ToolResult::ok("Restored {$type->name}", null, $this->typeCard($type->loadCount('awards'), 'start', 'positive', 'Restored'));
    }

    // ── Resolution ───────────────────────────────────────────────────────────

    /**
     * Exactly one award type by name — exact first, then a partial name only
     * one type has.
     *
     * @return array{0: AwardType|null, 1: string}
     */
    private function locate(string $needle, bool $archived = false): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [null, 'Say which award type.'];
        }

        $base = fn (): Builder => $archived ? AwardType::onlyTrashed() : AwardType::query();
        $matches = $base()->whereRaw('lower(name) = ?', [Str::lower($needle)])->limit(2)->get();

        if ($matches->isEmpty()) {
            $like = AwardType::query()->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
            $matches = $base()->where('name', $like, '%'.addcslashes($needle, '%_\\').'%')->catalogueOrder()->limit(6)->get();
        }

        return match (true) {
            $matches->isEmpty() => [null, ($archived ? 'No archived award type' : 'No award type').' matches “'.Str::limit($needle, 60).'”.'],
            $matches->count() === 1 => [$matches->first(), ''],
            default => [null, 'More than one award type matches “'.Str::limit($needle, 60).'”: '.$matches->pluck('name')->implode(', ').'.'],
        };
    }

    /**
     * Refuse a name another live type has in any case. The screen does not
     * insist; here it keeps two types from answering to one name.
     */
    private function nameTaken(string $name, ?AwardType $except = null): ?string
    {
        $taken = AwardType::query()
            ->whereRaw('lower(name) = ?', [Str::lower(trim($name))])
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->id))
            ->exists();

        return $taken ? "There is already an award type called “{$name}”." : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function typeCard(AwardType $type, string $kind, string $tone, string $badge): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $type->name,
            subtitle: filled($type->description) ? Str::limit((string) $type->description, 140) : null,
            meta: [isset($type->awards_count) ? 'Given '.$type->awards_count.' '.Str::plural('time', (int) $type->awards_count) : null],
            id: $type->hashid,
        );
    }
}
