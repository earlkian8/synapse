<?php

namespace App\Support\Roles;

use App\Http\Requests\RolePermission\StoreRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Everything that changes who can do what: a role's making, its permissions,
 * its deletion, and who holds it.
 *
 * The Roles & Permissions screen, role assignment on the Users screen (the form
 * and the bulk action) and the assistant all come through here, so access
 * changes hands by one set of rules ({@see GrantRules}) and is recorded the same
 * way whoever asked. Validation is {@see StoreRoleRequest}, which runs first.
 * Refusals are {@see RoleException}, worded to be shown as they are:
 *
 * - a permission can be granted only by someone who holds it, a role given
 *   only by someone who holds all it grants, and the HR Manager role given or
 *   taken only by an HR Manager — never the workspace's last one;
 * - the HR Manager role cannot be edited, nor a built-in role deleted;
 * - two roles may not share a label, whatever its case — a role is picked by
 *   its label on the screen and in the assistant.
 *
 * Role ids are resolved within the active workspace only: `role_user` is
 * global while roles are per-organisation (ADR 0023), so a person's roles in
 * their other companies are never touched.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class RoleWorkflow
{
    /**
     * Create a role granting the given permissions.
     *
     * @param  list<string>  $permissions
     *
     * @throws RoleException
     */
    public function create(string $label, string $name, ?string $description, array $permissions, User $actor, string $channel = ''): Role
    {
        $this->assertLabelFree($label);
        $this->assertGrantable($actor, $permissions);

        $role = DB::transaction(function () use ($label, $name, $description, $permissions): Role {
            $role = Role::create(['name' => $name, 'label' => $label, 'description' => $description]);
            $role->permissions()->sync($this->permissionIds($permissions));

            return $role;
        });

        ActivityLogger::log(
            event: 'created',
            description: "Created role {$role->label}{$channel}",
            subject: $role,
            properties: ['permissions' => array_values($permissions)],
            logName: 'roles',
            subjectLabel: $role->label,
        );

        return $role;
    }

    /**
     * Change a role's label, description and permissions. Only permissions it
     * does not already grant count as being granted: an editor may keep — or
     * take away — what they could not have given.
     *
     * @param  list<string>  $permissions
     *
     * @throws RoleException
     */
    public function update(Role $role, string $label, ?string $description, array $permissions, User $actor, string $channel = ''): Role
    {
        if ($role->isSuperAdmin()) {
            throw new RoleException("The {$role->label} role cannot be modified — it holds every permission.");
        }

        $this->assertLabelFree($label, $role);

        $current = $role->permissions()->pluck('name');
        $added = collect($permissions)->diff($current)->values();
        $removed = $current->diff($permissions)->values();

        $this->assertGrantable($actor, $added->all());

        DB::transaction(function () use ($role, $label, $description, $permissions): void {
            $role->update(['label' => $label, 'description' => $description]);
            $role->permissions()->sync($this->permissionIds($permissions));
        });

        ActivityLogger::log(
            event: 'updated',
            description: "Updated role {$role->label}{$channel}",
            subject: $role,
            properties: [
                'permissions' => array_values($permissions),
                'added' => $added->all(),
                'removed' => $removed->all(),
            ],
            logName: 'roles',
            subjectLabel: $role->label,
        );

        return $role;
    }

    /**
     * Delete a custom role. Its holders simply stop having it.
     *
     * @throws RoleException
     */
    public function delete(Role $role, string $channel = ''): void
    {
        if ($role->is_system) {
            throw new RoleException('Built-in system roles cannot be deleted.');
        }

        $label = $role->label;
        $holders = $role->users()->count();
        $role->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted role {$label}{$channel}",
            properties: ['holders' => $holders],
            logName: 'roles',
            subjectLabel: $label,
        );
    }

    /**
     * Delete several roles at once, skipping the built-in ones. Returns the
     * labels deleted and how many were skipped.
     *
     * @param  list<int>  $ids
     * @return array{deleted: list<string>, protected: int}
     */
    public function deleteMany(array $ids): array
    {
        $requested = Role::query()->whereKey($ids)->get();
        $roles = $requested->where('is_system', false)->values();
        $labels = $roles->pluck('label')->all();
        $protected = $requested->count() - $roles->count();

        if ($roles->isNotEmpty()) {
            Role::query()->whereKey($roles->pluck('id'))->delete();

            ActivityLogger::log(
                event: 'deleted',
                description: 'Bulk deleted '.$roles->count().' '.Str::plural('role', $roles->count()),
                properties: ['action' => 'delete', 'count' => $roles->count(), 'roles' => $labels, 'protected' => $protected],
                logName: 'roles',
            );
        }

        return ['deleted' => $labels, 'protected' => $protected];
    }

    // ── Who holds a role ─────────────────────────────────────────────────────

    /**
     * Give somebody a role. False when they already hold it.
     *
     * @throws RoleException
     */
    public function give(User $user, Role $role, User $actor, string $channel = ''): bool
    {
        if ($user->roles()->whereKey($role->id)->exists()) {
            return false;
        }

        $why = GrantRules::whyNotGive($actor, $role);

        if ($why !== null) {
            throw new RoleException($why);
        }

        $user->roles()->attach($role->id);
        $user->forgetCachedPermissions();

        $this->notifyGranted($user, collect([$role]), $actor);

        ActivityLogger::log(
            event: 'updated',
            description: "Gave {$user->full_name} the {$role->label} role{$channel}",
            subject: $user,
            properties: ['attached' => [$role->id]],
            logName: 'user_management',
            subjectLabel: $user->full_name,
        );

        return true;
    }

    /**
     * Take a role away from somebody. False when they do not hold it.
     *
     * @throws RoleException
     */
    public function take(User $user, Role $role, User $actor, string $channel = ''): bool
    {
        if (! $user->roles()->whereKey($role->id)->exists()) {
            return false;
        }

        $why = GrantRules::whyNotTake($actor, $role, $user);

        if ($why !== null) {
            throw new RoleException($why);
        }

        $user->roles()->detach($role->id);
        $user->forgetCachedPermissions();

        ActivityLogger::log(
            event: 'updated',
            description: "Took the {$role->label} role from {$user->full_name}{$channel}",
            subject: $user,
            properties: ['detached' => [$role->id]],
            logName: 'user_management',
            subjectLabel: $user->full_name,
        );

        return true;
    }

    /**
     * Work out — and check, before anything is saved — how a person's roles in
     * this workspace change to become exactly the given ones. Ids of another
     * workspace's roles are ignored.
     *
     * @param  list<int|string>  $roleIds
     * @return array{attach: Collection<int, Role>, detach: Collection<int, Role>}
     *
     * @throws RoleException
     */
    public function plan(?User $user, array $roleIds, User $actor): array
    {
        $roles = Role::query()->get()->keyBy('id');
        $selected = collect($roleIds)->map(fn (mixed $id): int => (int) $id)->filter(fn (int $id): bool => $roles->has($id))->unique();
        $current = $user === null ? collect() : $user->roles()->pluck('roles.id');

        $attach = $selected->diff($current)->map(fn (int $id): Role => $roles->get($id))->values();
        $detach = $current->diff($selected)->map(fn (int $id): Role => $roles->get($id))->filter()->values();

        foreach ($attach as $role) {
            $why = GrantRules::whyNotGive($actor, $role);

            if ($why !== null) {
                throw new RoleException($why);
            }
        }

        foreach ($detach as $role) {
            $why = $user === null ? null : GrantRules::whyNotTake($actor, $role, $user);

            if ($why !== null) {
                throw new RoleException($why);
            }
        }

        return ['attach' => $attach, 'detach' => $detach];
    }

    /**
     * Carry out a {@see plan()}, and tell the person about any role they gained.
     * The caller records it, as part of the change it belongs to.
     *
     * @param  array{attach: Collection<int, Role>, detach: Collection<int, Role>}  $plan
     * @return array{attached: list<int>, detached: list<int>}
     */
    public function apply(User $user, array $plan, User $actor): array
    {
        if ($plan['detach']->isNotEmpty()) {
            $user->roles()->detach($plan['detach']->pluck('id')->all());
        }

        if ($plan['attach']->isNotEmpty()) {
            $user->roles()->attach($plan['attach']->pluck('id')->all());
            $this->notifyGranted($user, $plan['attach'], $actor);
        }

        $user->forgetCachedPermissions();

        return [
            'attached' => $plan['attach']->pluck('id')->values()->all(),
            'detached' => $plan['detach']->pluck('id')->values()->all(),
        ];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $permissions
     *
     * @throws RoleException
     */
    private function assertGrantable(User $actor, array $permissions): void
    {
        $beyond = GrantRules::beyond($actor, $permissions);

        if ($beyond !== []) {
            throw new RoleException('You can only grant access you have yourself — not '.GrantRules::labels($beyond).'.');
        }
    }

    /**
     * @throws RoleException
     */
    private function assertLabelFree(string $label, ?Role $except = null): void
    {
        $taken = Role::query()
            ->whereRaw('lower(label) = ?', [Str::lower(trim($label))])
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except->id))
            ->exists();

        if ($taken) {
            throw new RoleException('There is already a role called “'.trim($label).'”.');
        }
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    private function notifyGranted(User $user, Collection $roles, User $actor): void
    {
        Notifier::toUser(
            $user,
            'Your access has changed',
            "You've been granted the following role(s): {$roles->pluck('label')->implode(', ')}.",
            url: '/dashboard',
            level: 'info',
            category: 'account',
            actor: $actor,
        );
    }

    /**
     * @param  list<string>  $names
     * @return Collection<int, int>
     */
    private function permissionIds(array $names): Collection
    {
        return Permission::query()->whereIn('name', $names)->pluck('id');
    }
}
