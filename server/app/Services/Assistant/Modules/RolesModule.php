<?php

namespace App\Services\Assistant\Modules;

use App\Http\Requests\RolePermission\StoreRoleRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Assistant\Contracts\ContributesTopicContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\ToolResult;
use App\Support\PermissionRegistry;
use App\Support\Roles\GrantRules;
use App\Support\Roles\RoleException;
use App\Support\Roles\RoleWorkflow;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Roles & Permissions capability (ADR 0057): what each role of this workspace
 * lets its holders do, and who holds it.
 *
 * **Reading** answers "what roles do we have?", "what can a Department Head
 * do?", "which role lets someone approve leave?", "which permission is for
 * exporting employees?". **Doing** creates a role (from permissions, or by
 * copying one), renames it, grants or revokes its permissions and deletes it,
 * through {@see RoleWorkflow} — the screen's own path — against the screen's own
 * rules ({@see StoreRoleRequest}).
 *
 * A permission is named the way people say it — "approve leave", or a whole
 * group like "Leave Management" — and resolved by
 * {@see PermissionRegistry::lookup()}. Nobody grants what they do not hold
 * ({@see GrantRules}). Granting, revoking and deleting reach everyone who holds
 * the role, so they wait for the user's Confirm (ADR 0049) and the card says
 * who would gain or lose what. Who holds a role is the Users capability's.
 */
class RolesModule extends Module implements ContributesTopicContext, ExplainsConsequences
{
    private const CHANNEL = ' via assistant';

    /** How many holders a role read names at most. */
    private const MAX_HOLDERS = 10;

    /** How many permissions a search lists at most. */
    private const MAX_PERMISSIONS = 15;

    public function __construct(private readonly RoleWorkflow $workflow) {}

    public function key(): string
    {
        return 'roles';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('roles.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_roles' => 'findRoles',
            'get_role' => 'getRole',
            'find_permissions' => 'findPermissions',
            'create_role' => 'createRole',
            'update_role' => 'updateRole',
            'grant_role_permissions' => 'grant',
            'revoke_role_permissions' => 'revoke',
            'delete_role' => 'deleteRole',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'find_roles' => 'roles.view',
            'get_role' => 'roles.view',
            'find_permissions' => 'roles.view',
            'create_role' => 'roles.create',
            'update_role' => 'roles.update',
            'grant_role_permissions' => 'roles.update',
            'revoke_role_permissions' => 'roles.update',
            'delete_role' => 'roles.delete',
        ];
    }

    protected function confirmTools(): array
    {
        // Each reaches every holder of the role at once.
        return ['grant_role_permissions', 'revoke_role_permissions', 'delete_role'];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        if ($user->cannot($this->permissionMap()[$tool])) {
            return $this->denied('do that with roles');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $roles = $this->catalog(Role::query()->orderByDesc('is_system')->orderBy('label')->pluck('label'));
        $groups = implode(', ', array_keys(PermissionRegistry::GROUPS));

        return <<<TXT
        ROLES — what each role of this workspace lets its holders do. Roles: {$roles}. The HR Manager role holds every permission and cannot be edited. Permission groups: {$groups}.
        - find_roles lists roles (optionally those granting a permission); get_role reads one's permissions and holders; find_permissions finds a permission by what it lets someone do, and which roles grant it.
        - Name permissions the way the user says them ("approve leave", "export employees") or by a group name for all of it.
        - create_role (from permissions, or copy_from another role) and update_role (label, description) run directly. grant_role_permissions, revoke_role_permissions and delete_role wait for the user's confirmation. Nobody can grant a permission they do not hold themselves.
        - Giving somebody a role is the users capability (give_user_role).
        TXT;
    }

    public function tools(User $user): array
    {
        $role = ['type' => 'STRING', 'description' => 'The role, by its label.'];
        $permissions = ['type' => 'ARRAY', 'items' => ['type' => 'STRING'], 'description' => 'Permissions by what they let someone do ("approve leave"), their key, or a group name for all of it.'];

        return $this->permitted($user, [
            ['name' => 'find_roles', 'description' => "List this workspace's roles, optionally only those granting a permission.", 'parameters' => ['type' => 'OBJECT', 'properties' => ['search' => ['type' => 'STRING'], 'permission' => ['type' => 'STRING']]]],
            ['name' => 'get_role', 'description' => 'Read one role: its permissions by group, and who holds it.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['role' => $role], 'required' => ['role']]],
            ['name' => 'find_permissions', 'description' => 'Find permissions by what they let someone do, and which roles grant each.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['search' => ['type' => 'STRING']], 'required' => ['search']]],
            [
                'name' => 'create_role',
                'description' => 'Create a role from permissions, or by copying another role.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => ['label' => ['type' => 'STRING'], 'description' => ['type' => 'STRING'], 'permissions' => $permissions, 'copy_from' => $role],
                    'required' => ['label'],
                ],
            ],
            ['name' => 'update_role', 'description' => 'Rename a role or change its description.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['role' => $role, 'new_label' => ['type' => 'STRING'], 'description' => ['type' => 'STRING']], 'required' => ['role']]],
            ['name' => 'grant_role_permissions', 'description' => 'Add permissions to a role — everyone holding it gains them.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['role' => $role, 'permissions' => $permissions], 'required' => ['role', 'permissions']]],
            ['name' => 'revoke_role_permissions', 'description' => 'Remove permissions from a role — everyone holding it loses them.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['role' => $role, 'permissions' => $permissions], 'required' => ['role', 'permissions']]],
            ['name' => 'delete_role', 'description' => 'Delete a custom role; its holders stop having it.', 'parameters' => ['type' => 'OBJECT', 'properties' => ['role' => $role], 'required' => ['role']]],
        ]);
    }

    // ── Retrieval ────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    public function topicTriggers(): array
    {
        // Not a bare "role": a posting's role is recruitment's.
        return [
            'permission', 'permissions', 'access rights', 'user role', 'user roles', 'roles and permissions',
            'which role', 'what role', 'what roles', 'our roles', 'who can', 'allowed to',
        ];
    }

    public function topicContext(User $user): ?ContextSection
    {
        if ($user->cannot('roles.view') || ! app(Tenancy::class)->check()) {
            return null;
        }

        $roles = Role::query()->withCount(['permissions', 'users' => fn (Builder $q) => $q->inCurrentOrganization()])
            ->orderByDesc('is_system')->orderBy('label')->get();

        return ContextSection::of('Roles', $roles->map(fn (Role $r): string => "{$r->label} ("
            .($r->isSuperAdmin() ? 'every permission' : "{$r->permissions_count} ".Str::plural('permission', (int) $r->permissions_count))
            ."; {$r->users_count} ".Str::plural('holder', (int) $r->users_count).($r->is_system ? '; built-in' : '').')')->all());
    }

    // ── Consequences ─────────────────────────────────────────────────────────

    public function consequence(User $user, string $tool, array $args): ?string
    {
        if (! in_array($tool, ['grant_role_permissions', 'revoke_role_permissions', 'delete_role'], true)) {
            return null;
        }

        [$role] = $this->resolveRole((string) ($args['role'] ?? ''));

        if ($role === null) {
            return null;
        }

        $holders = $this->holders($role)->count();
        $people = "{$holders} ".Str::plural('person', $holders).($holders === 1 ? ' holds' : ' hold');

        if ($tool === 'delete_role') {
            return "{$people} the {$role->label} role and would lose what only it gives them.";
        }

        [$names] = $this->permissionNames((array) ($args['permissions'] ?? []));
        $current = $role->permissions()->pluck('name');
        $changing = $tool === 'grant_role_permissions' ? collect($names)->diff($current) : collect($names)->intersect($current);

        if ($changing->isEmpty()) {
            return null;
        }

        return "{$people} the {$role->label} role and would ".($tool === 'grant_role_permissions' ? 'gain' : 'lose').' '.GrantRules::labels($changing->values()).'.';
    }

    // ── Reads ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findRoles(User $user, array $args): ToolResult
    {
        $query = Role::query()->withCount(['permissions', 'users' => fn (Builder $q) => $q->inCurrentOrganization()]);
        $label = 'Listed roles';

        if (filled($args['permission'] ?? null)) {
            [$names, $error] = PermissionRegistry::lookup((string) $args['permission']);

            if ($names === []) {
                return ToolResult::error('Looked up the permission', $error);
            }

            $query->where(fn (Builder $q) => $q
                ->where('name', Role::SUPER_ADMIN)
                ->orWhereHas('permissions', fn (Builder $p) => $p->whereIn('name', $names)));
            $label = 'Listed roles granting '.GrantRules::labels($names, 2);
        }

        if (filled($args['search'] ?? null)) {
            $query->search((string) $args['search']);
        }

        $cards = $query->orderByDesc('is_system')->orderBy('label')->get()
            ->map(fn (Role $r): array => $this->roleCard($r, 'find', 'neutral'))
            ->all();

        return ToolResult::found($label, $cards === [] ? 'None' : count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function getRole(User $user, array $args): ToolResult
    {
        [$role, $error] = $this->resolveRole((string) ($args['role'] ?? ''));

        if ($role === null) {
            return ToolResult::error('Looked up the role', $error);
        }

        $role->loadCount(['permissions', 'users' => fn (Builder $q) => $q->inCurrentOrganization()]);
        $held = $role->permissions()->pluck('name');
        $holders = $this->holders($role)->orderBy('first_name')->limit(self::MAX_HOLDERS)->get();

        $card = $this->roleCard($role, 'insight', 'info');
        $card['meta'] = array_values(array_filter([
            ...$card['meta'],
            ...($role->isSuperAdmin() ? ['Every permission, in every group'] : $this->byGroup($held)),
            'Held by: '.($holders->isEmpty() ? 'nobody' : $this->catalog($holders->map(fn (User $u): string => $u->full_name))
                .($role->users_count > $holders->count() ? ' and '.($role->users_count - $holders->count()).' more' : '')),
            filled($role->description) ? 'About: '.Str::limit((string) $role->description, 200) : null,
        ]));

        return ToolResult::found("Read the {$role->label} role", null, [$card]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function findPermissions(User $user, array $args): ToolResult
    {
        $needle = Str::lower(trim((string) ($args['search'] ?? '')));

        if ($needle === '') {
            return ToolResult::error('Looked up permissions', 'Say what the permission should let someone do.');
        }

        $words = preg_split('/\s+/', $needle) ?: [];
        $matches = PermissionRegistry::all()->filter(function (array $p, string $name) use ($words): bool {
            $haystack = Str::lower("{$name} {$p['label']} {$p['group']}");

            return collect($words)->every(fn (string $word): bool => str_contains($haystack, $word));
        })->take(self::MAX_PERMISSIONS);

        $grantedBy = Role::query()->with('permissions:id,name')->orderBy('label')->get();

        $cards = $matches->map(fn (array $p, string $name): array => $this->card(
            kind: 'find',
            tone: 'neutral',
            badge: $p['group'],
            title: $p['label'],
            subtitle: $name,
            meta: ['Granted by: '.$this->catalog($grantedBy
                ->filter(fn (Role $r): bool => $r->isSuperAdmin() || $r->permissions->contains('name', $name))
                ->pluck('label'))],
        ))->values()->all();

        return ToolResult::found('Looked up permissions', $cards === [] ? 'None match' : count($cards).' found', $cards);
    }

    // ── Writes ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function createRole(User $user, array $args): ToolResult
    {
        $label = trim((string) ($args['label'] ?? ''));
        [$names, $error] = $this->permissionNames((array) ($args['permissions'] ?? []));

        if ($error !== null) {
            return ToolResult::error('Created the role', $error);
        }

        if (filled($args['copy_from'] ?? null)) {
            [$source, $error] = $this->resolveRole((string) $args['copy_from']);

            if ($source === null) {
                return ToolResult::error('Created the role', $error);
            }

            if ($source->isSuperAdmin()) {
                return ToolResult::error('Created the role', "The {$source->label} role holds everything by being what it is, so it cannot be copied. Name the permissions instead.");
            }

            $names = collect($source->permissions()->pluck('name'))->merge($names)->unique()->values()->all();
        }

        $data = [
            'label' => $label,
            'name' => Str::slug($label),
            'description' => filled($args['description'] ?? null) ? trim((string) $args['description']) : null,
            'permissions' => $names,
        ];

        $problem = $this->invalid($data, StoreRoleRequest::rulesFor(), StoreRoleRequest::messagesFor());

        if ($problem !== null) {
            return ToolResult::error('Created the role', $problem);
        }

        try {
            $role = $this->workflow->create($data['label'], $data['name'], $data['description'], $names, $user, self::CHANNEL);
        } catch (RoleException $e) {
            return ToolResult::error('Created the role', $e->getMessage());
        }

        return ToolResult::ok(
            "Created the {$role->label} role",
            'Nobody holds it yet — give it to people with give_user_role.',
            $this->roleCard($role->loadCount(['permissions', 'users']), 'add', 'positive', 'Created'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function updateRole(User $user, array $args): ToolResult
    {
        [$role, $error] = $this->resolveRole((string) ($args['role'] ?? ''));

        if ($role === null) {
            return ToolResult::error('Looked up the role', $error);
        }

        $label = filled($args['new_label'] ?? null) ? trim((string) $args['new_label']) : $role->label;
        $description = filled($args['description'] ?? null) ? trim((string) $args['description']) : $role->description;

        if ($label === $role->label && $description === $role->description) {
            return ToolResult::error('Updated the role', 'Say what to change: its label or its description.');
        }

        return $this->save($role, $label, $description, $role->permissions()->pluck('name')->all(), $user, "Updated the {$label} role", null);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function grant(User $user, array $args): ToolResult
    {
        return $this->changePermissions($user, $args, grant: true);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function revoke(User $user, array $args): ToolResult
    {
        return $this->changePermissions($user, $args, grant: false);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function deleteRole(User $user, array $args): ToolResult
    {
        [$role, $error] = $this->resolveRole((string) ($args['role'] ?? ''));

        if ($role === null) {
            return ToolResult::error('Looked up the role', $error);
        }

        $holders = $this->holders($role)->count();

        try {
            $this->workflow->delete($role, self::CHANNEL);
        } catch (RoleException $e) {
            return ToolResult::error('Deleted the role', $e->getMessage());
        }

        return ToolResult::ok("Deleted the {$role->label} role", "{$holders} ".Str::plural('person', $holders).' no longer '.($holders === 1 ? 'holds' : 'hold').' it.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function changePermissions(User $user, array $args, bool $grant): ToolResult
    {
        $verb = $grant ? 'Granted' : 'Revoked';
        [$role, $error] = $this->resolveRole((string) ($args['role'] ?? ''));

        if ($role === null) {
            return ToolResult::error('Looked up the role', $error);
        }

        [$names, $error] = $this->permissionNames((array) ($args['permissions'] ?? []));

        if ($error !== null || $names === []) {
            return ToolResult::error("{$verb} permissions", $error ?? 'Say which permissions.');
        }

        $current = $role->permissions()->pluck('name');
        $changing = $grant ? collect($names)->diff($current) : collect($names)->intersect($current);

        if ($changing->isEmpty()) {
            return ToolResult::error("{$verb} permissions", $grant
                ? "The {$role->label} role already grants ".GrantRules::labels($names).'.'
                : "The {$role->label} role does not grant ".GrantRules::labels($names).'.');
        }

        $next = $grant ? $current->merge($changing) : $current->diff($changing);

        return $this->save(
            $role,
            $role->label,
            $role->description,
            $next->unique()->values()->all(),
            $user,
            ($grant ? 'Granted ' : 'Revoked ').GrantRules::labels($changing->values(), 2).($grant ? ' to ' : ' from ')."the {$role->label} role",
            $this->holders($role)->count().' '.Str::plural('holder', $this->holders($role)->count()).($grant ? ' gained ' : ' lost ').$changing->count().' '.Str::plural('permission', $changing->count()).'.',
        );
    }

    /**
     * @param  list<string>  $permissions
     */
    private function save(Role $role, string $label, ?string $description, array $permissions, User $user, string $done, ?string $detail): ToolResult
    {
        $problem = $this->invalid(
            ['label' => $label, 'description' => $description, 'permissions' => $permissions],
            collect(StoreRoleRequest::rulesFor())->except('name')->all(),
        );

        if ($problem !== null) {
            return ToolResult::error('Updated the role', $problem);
        }

        try {
            $this->workflow->update($role, $label, $description, $permissions, $user, self::CHANNEL);
        } catch (RoleException $e) {
            return ToolResult::error('Updated the role', $e->getMessage());
        }

        return ToolResult::ok($done, $detail, $this->roleCard($role->loadCount(['permissions', 'users' => fn (Builder $q) => $q->inCurrentOrganization()]), 'edit', 'info', 'Updated'));
    }

    /**
     * Every phrase resolved to permission keys — or the first that is not, and
     * none. A list is acted on whole or not at all.
     *
     * @param  array<int, mixed>  $phrases
     * @return array{0: list<string>, 1: string|null}
     */
    private function permissionNames(array $phrases): array
    {
        $names = [];

        foreach ($phrases as $phrase) {
            [$found, $error] = PermissionRegistry::lookup(is_scalar($phrase) ? (string) $phrase : '');

            if ($found === []) {
                return [[], $error];
            }

            array_push($names, ...$found);
        }

        return [array_values(array_unique($names)), null];
    }

    /**
     * The members of this workspace holding the role.
     *
     * @return Builder<User>
     */
    private function holders(Role $role): Builder
    {
        return User::query()->inCurrentOrganization()->whereHas('roles', fn (Builder $q) => $q->whereKey($role->id));
    }

    /**
     * Permissions as "Group: label, label".
     *
     * @param  Collection<int, string>  $held
     * @return list<string>
     */
    private function byGroup(Collection $held): array
    {
        if ($held->isEmpty()) {
            return ['No permissions yet'];
        }

        return collect(PermissionRegistry::GROUPS)
            ->map(function (array $permissions, string $group) use ($held): ?string {
                $labels = collect($permissions)->only($held->all())->values();

                return $labels->isEmpty() ? null : "{$group}: ".$labels->implode(', ');
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function roleCard(Role $role, string $kind, string $tone, ?string $badge = null): array
    {
        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge ?? ($role->is_system ? 'Built-in' : 'Custom'),
            title: $role->label,
            subtitle: $role->name,
            meta: [
                $role->isSuperAdmin() ? 'Every permission' : ((int) $role->permissions_count).' '.Str::plural('permission', (int) $role->permissions_count),
                ((int) $role->users_count).' '.Str::plural('holder', (int) $role->users_count),
            ],
            id: $role->id,
        );
    }
}
