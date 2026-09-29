<?php

namespace App\Support\Roles;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionRegistry;
use Illuminate\Support\Collection;

/**
 * Who may hand out which access (ADR 0057). One rule, asked by every place
 * access changes hands — the role editor, role assignment on the Users screen
 * and its bulk action, and the assistant:
 *
 * **You can only give what you hold.** A permission may be added to a role
 * only by someone who holds it, a role given only by someone who holds all of
 * its permissions, and the HR Manager role — which holds everything by
 * bypassing every gate — given or taken away only by an HR Manager.
 *
 * Without it, `roles.assign` or `roles.update` was the whole of the system:
 * the holder could give themselves HR Manager, or add any permission to their
 * own role. The same measure decides whose *account* someone may change
 * ({@see outranks()}): resetting the password of somebody with more access is
 * the same escalation by another door.
 */
final class GrantRules
{
    /**
     * The permissions this person could grant — null when they may grant any
     * (an HR Manager).
     *
     * @return Collection<int, string>|null
     */
    public static function grantable(User $actor): ?Collection
    {
        return $actor->isSuperAdmin() ? null : $actor->permissionNames();
    }

    /**
     * The permissions among these that this person could not grant.
     *
     * @param  iterable<string>  $permissions
     * @return list<string>
     */
    public static function beyond(User $actor, iterable $permissions): array
    {
        $grantable = self::grantable($actor);

        if ($grantable === null) {
            return [];
        }

        return collect($permissions)->diff($grantable)->unique()->values()->all();
    }

    /**
     * Whether this person could give the role to somebody.
     */
    public static function canGive(User $actor, Role $role): bool
    {
        return self::whyNotGive($actor, $role) === null;
    }

    /**
     * Why this person may not give the role, or null when they may.
     */
    public static function whyNotGive(User $actor, Role $role): ?string
    {
        if ($role->isSuperAdmin()) {
            return $actor->isSuperAdmin() ? null : "Only an {$role->label} can make somebody an {$role->label}.";
        }

        $beyond = self::beyond($actor, $role->permissions()->pluck('name'));

        return $beyond === [] ? null : "The {$role->label} role grants access you don't have yourself (".self::labels($beyond)."), so you can't give it.";
    }

    /**
     * Why this person may not take the role away from its holder, or null when
     * they may. Only the HR Manager role is guarded: it takes an HR Manager, and
     * the workspace always keeps one who can sign in.
     */
    public static function whyNotTake(User $actor, Role $role, User $holder): ?string
    {
        if (! $role->isSuperAdmin()) {
            return null;
        }

        if (! $actor->isSuperAdmin()) {
            return "Only an {$role->label} can take the {$role->label} role away.";
        }

        return self::lastHolder($role, $holder)
            ? "{$holder->full_name} is the workspace's only active {$role->label}. Make somebody else one first."
            : null;
    }

    /**
     * Whether the target holds access the actor does not — in which case the
     * actor may not change the target's account (their sign-in, their status,
     * whether they are here at all). An HR Manager outranks everyone but
     * another HR Manager.
     */
    public static function outranks(User $target, User $actor): bool
    {
        if ($actor->isSuperAdmin()) {
            return false;
        }

        if ($target->isSuperAdmin()) {
            return true;
        }

        return $target->permissionNames()->diff($actor->permissionNames())->isNotEmpty();
    }

    /**
     * Whether this holder is the last active member of this workspace with the
     * role — losing them would leave nobody able to administer it.
     */
    public static function lastHolder(Role $role, User $holder): bool
    {
        return ! $role->users()
            ->inCurrentOrganization()
            ->where('is_active', true)
            ->whereKeyNot($holder->getKey())
            ->exists();
    }

    /**
     * Permission names as their labels, for a message: "Approve / reject
     * leave & set balances, Export employees".
     *
     * @param  iterable<string>  $permissions
     */
    public static function labels(iterable $permissions, int $max = 4): string
    {
        $all = PermissionRegistry::all();
        $labels = collect($permissions)->map(fn (string $name): string => $all->get($name)['label'] ?? $name)->values();

        return $labels->take($max)->implode(', ').($labels->count() > $max ? ' and '.($labels->count() - $max).' more' : '');
    }
}
