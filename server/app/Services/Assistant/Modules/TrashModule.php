<?php

namespace App\Services\Assistant\Modules;

use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\Tenancy;
use App\Support\Trash\TrashBin;
use App\Support\Trash\TrashException;
use App\Support\Trash\TrashRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Trash Bin capability (ADR 0057): the archived records of this workspace —
 * accounts, employees, departments and leave types — and bringing one back or
 * deleting it for good.
 *
 * Everything goes through {@see TrashBin}, the screen's own path, so the rules
 * are the screen's: a type is seen only with its module's view permission, and
 * restored or deleted only with that module's own permission
 * ({@see TrashRegistry::allows()}); an account is deleted only if the Users
 * screen would allow it. Records are found in this workspace only
 * ({@see TrashRegistry::trashed()}).
 *
 * One record at a time, and both restoring and deleting wait for the user's
 * Confirm (ADR 0049): restoring an account lets somebody sign in again, and a
 * permanent delete cannot be undone — for an employee it takes their records
 * with them. Emptying the whole bin stays on the screen.
 */
class TrashModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    private const CHANNEL = ' via assistant';

    /** How many archived records a list returns at most. */
    private const MAX_ROWS = 15;

    public function __construct(private readonly TrashBin $bin) {}

    public function key(): string
    {
        return 'trash';
    }

    public function isAvailable(User $user): bool
    {
        return TrashRegistry::viewableTypes($user) !== [];
    }

    protected function toolMap(): array
    {
        return [
            'find_trash' => 'findTrash',
            'restore_from_trash' => 'restore',
            'delete_from_trash' => 'forceDelete',
        ];
    }

    protected function confirmTools(): array
    {
        return ['restore_from_trash', 'delete_from_trash'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        if (! $this->isAvailable($user)) {
            return $this->denied('see the trash bin');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $types = collect(TrashRegistry::viewableTypes($user))
            ->map(fn (string $type): string => "{$type} (".TrashRegistry::definition($type)['plural'].')')
            ->implode(', ');

        return <<<TXT
        TRASH BIN — archived records of this workspace this user may see: {$types}.
        - find_trash lists them (by type and name). restore_from_trash brings one back; delete_from_trash deletes one for good. Both wait for the user's confirmation, one record at a time.
        - Deleting for good cannot be undone; an employee's attendance, leave and other records go with them. Emptying the whole bin is only on the Trash Bin screen.
        TXT;
    }

    public function tools(User $user): array
    {
        $viewable = TrashRegistry::viewableTypes($user);
        $type = ['type' => 'STRING', 'enum' => $viewable];
        $item = ['type' => 'STRING', 'description' => 'The record, by name (or email / employee number / code).'];

        $tools = [
            ['name' => 'find_trash', 'description' => 'List archived records, optionally of one type or matching a name.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['type' => $type, 'search' => ['type' => 'STRING']]]],
        ];

        // Offered only when some visible type allows it — the runtime check per
        // type still decides.
        if ($this->anyAllows($user, 'restore')) {
            $tools[] = ['name' => 'restore_from_trash', 'description' => 'Bring one archived record back.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['type' => $type, 'item' => $item], 'required' => ['type', 'item']]];
        }

        if ($this->anyAllows($user, 'forceDelete')) {
            $tools[] = ['name' => 'delete_from_trash', 'description' => 'Permanently delete one archived record. Cannot be undone.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['type' => $type, 'item' => $item], 'required' => ['type', 'item']]];
        }

        return $tools;
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        return ['trash', 'trash bin', 'archived', 'recycle bin', 'deleted records', 'restore'];
    }

    public function topicContext(User $user): ?ContextSection
    {
        if (! $this->isAvailable($user) || ! app(Tenancy::class)->check()) {
            return null;
        }

        $counts = collect(TrashRegistry::viewableTypes($user))
            ->map(fn (string $type): string => TrashRegistry::definition($type)['plural'].' '.TrashRegistry::trashed($type)->count())
            ->implode(', ');

        return ContextSection::of('Trash bin', ["Archived in this workspace: {$counts}."]);
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        [$type, $model] = $this->locate($user, $args);

        if ($model === null) {
            return null;
        }

        $name = $this->bin->label($type, $model);

        if ($tool === 'restore_from_trash') {
            return match ($type) {
                'user' => "{$name} could sign in again, with the roles they held here.",
                'employee' => "{$name} would be back on the employee list and in reports.",
                default => "{$name} would be back in the lists it appears in.",
            };
        }

        return match ($type) {
            'employee' => "{$name} and everything recorded against them — attendance, leave, reviews, documents — would be deleted for good. This cannot be undone.",
            'user' => "{$name}'s account would be deleted for good. This cannot be undone.",
            default => "{$name} would be deleted for good. This cannot be undone.",
        };
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findTrash(User $user, array $args): ToolResult
    {
        $viewable = TrashRegistry::viewableTypes($user);
        $types = filled($args['type'] ?? null) ? array_values(array_intersect([(string) $args['type']], $viewable)) : $viewable;

        if ($types === []) {
            return ToolResult::error('Looked in the trash bin', 'You cannot see that kind of record.');
        }

        $search = trim((string) ($args['search'] ?? ''));
        $rows = collect();

        foreach ($types as $type) {
            $query = TrashRegistry::trashed($type);

            if ($search !== '') {
                $this->matchByTokens($query, $search);
            }

            $query->get()->each(fn (Model $model) => $rows->push([$type, $model]));
        }

        $total = $rows->count();
        $cards = $rows->sortByDesc(fn (array $row): int => (int) $row[1]->deleted_at?->getTimestamp())
            ->take(self::MAX_ROWS)
            ->map(fn (array $row): array => $this->itemCard($row[0], $row[1], 'find', 'neutral'))
            ->values()
            ->all();

        return ToolResult::found('Looked in the trash bin', $total === 0 ? 'Empty' : ($total > count($cards) ? "{$total} archived; the latest ".count($cards).' shown' : "{$total} archived"), $cards);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function restore(User $user, array $args): ToolResult
    {
        [$type, $model, $error] = $this->locate($user, $args, 'restore');

        if ($model === null) {
            return ToolResult::error('Restored from the trash', $error);
        }

        $name = $this->bin->restore($type, $model, self::CHANNEL);

        return ToolResult::ok("Restored {$name}", null, $this->itemCard($type, $model->fresh() ?? $model, 'edit', 'positive', 'Restored'));
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function forceDelete(User $user, array $args): ToolResult
    {
        [$type, $model, $error] = $this->locate($user, $args, 'forceDelete');

        if ($model === null) {
            return ToolResult::error('Deleted from the trash', $error);
        }

        $card = $this->itemCard($type, $model, 'remove', 'warning', 'Deleted');

        try {
            $name = $this->bin->forceDelete($type, $model, $user, self::CHANNEL);
        } catch (TrashException $e) {
            return ToolResult::error('Deleted from the trash', $e->getMessage());
        }

        return ToolResult::ok("Deleted {$name} for good", null, $card);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Exactly one archived record of the given type that this person may act
     * on, or why not.
     *
     * @param  array<string, mixed>  $args
     * @return array{0: string, 1: Model|null, 2: string}
     */
    private function locate(User $user, array $args, string $ability = 'view'): array
    {
        $type = (string) ($args['type'] ?? '');
        $needle = trim((string) ($args['item'] ?? ''));
        $definition = TrashRegistry::definition($type);

        if ($definition === null || ! in_array($type, TrashRegistry::viewableTypes($user), true)) {
            return [$type, null, 'You cannot see that kind of record.'];
        }

        if ($ability !== 'view' && ! TrashRegistry::allows($user, $type, $ability)) {
            return [$type, null, "You don't have permission to ".($ability === 'restore' ? 'restore' : 'permanently delete').' '.Str::lower($definition['plural']).'.'];
        }

        if ($needle === '') {
            return [$type, null, 'Say which one.'];
        }

        $matches = $this->matchByTokens(TrashRegistry::trashed($type), $needle)->limit(10)->get();

        if ($matches->count() > 1) {
            $exact = $matches->filter(fn (Model $m): bool => Str::lower($this->bin->label($type, $m)) === Str::lower($needle));

            if ($exact->count() === 1) {
                return [$type, $exact->first(), ''];
            }
        }

        return match ($matches->count()) {
            0 => [$type, null, 'No archived '.Str::lower($definition['label']).' matches “'.Str::limit($needle, 60).'”.'],
            1 => [$type, $matches->first(), ''],
            default => [$type, null, 'More than one archived '.Str::lower($definition['label']).' matches “'.Str::limit($needle, 60).'”. Use the full name.'],
        };
    }

    private function anyAllows(User $user, string $ability): bool
    {
        foreach (TrashRegistry::viewableTypes($user) as $type) {
            if (TrashRegistry::allows($user, $type, $ability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemCard(string $type, Model $model, string $kind, string $tone, ?string $badge = null): array
    {
        $definition = TrashRegistry::definition($type);

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge ?? $definition['label'],
            title: $this->bin->label($type, $model),
            subtitle: match ($type) {
                'user' => (string) $model->email,
                'employee' => (string) $model->employee_no,
                default => $model->code !== null ? (string) $model->code : null,
            },
            meta: [
                $badge !== null ? $definition['label'] : null,
                $model->deleted_at !== null ? 'Archived '.$model->deleted_at->diffForHumans() : null,
            ],
            id: $model->getKey(),
        );
    }
}
