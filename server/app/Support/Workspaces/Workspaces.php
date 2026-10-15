<?php

namespace App\Support\Workspaces;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\OrganizationProvisioner;
use App\Support\Roles\GrantRules;
use App\Support\Tenancy;
use App\Support\Users\UserAccounts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A person's own workspaces (ADR 0089): starting a new one, leaving one, and
 * the one rule that guards every way out of a company.
 *
 * A user is one identity that can belong to several companies (ADR 0023). An
 * HR officer who keeps the books for two employers creates the second company
 * from inside the app rather than registering again, and when they leave one
 * employer — resigning, or let go — their place there ends while the account
 * and the other company stay.
 *
 * **Nobody leaves a company without someone in charge of it.** A person who is
 * a workspace's only active HR Manager cannot leave it, or delete their
 * account, while other people still work in it: those people would be left in
 * a company nobody can administer. Another HR Manager is named first, on the
 * Users screen. A workspace with nobody else in it may be left; it then closes
 * for good, which is why the screen makes them type its name.
 *
 * Removal by the company — archiving somebody on the Users screen whose
 * account also belongs to another workspace — ends here too ({@see remove()}),
 * through {@see UserAccounts}.
 */
final class Workspaces
{
    /**
     * Start a new workspace owned by this person: the organisation, its roles,
     * its join code, and them as its HR Manager. It is logged inside the new
     * workspace, so its activity log opens with how it began.
     */
    public static function create(User $owner, string $name): Organization
    {
        $organization = DB::transaction(fn (): Organization => OrganizationProvisioner::found(trim($name), $owner));

        app(Tenancy::class)->runFor($organization, fn () => ActivityLogger::log(
            event: 'created',
            description: "{$owner->full_name} created the workspace",
            subject: $organization,
            logName: 'system',
            subjectLabel: $organization->name,
        ));

        return $organization;
    }

    /**
     * Why this person may not leave the workspace, or null when they may.
     */
    public static function whyNotLeave(User $user, Organization $organization): ?string
    {
        if (! $user->isMemberOf($organization)) {
            return "You are not a member of {$organization->name}.";
        }

        if (self::isLastManagerOfOthers($user, $organization)) {
            return "You are the only HR Manager of {$organization->name}, and other people still work in it. Make one of them an HR Manager first, on its User Management screen, so the company is not left without one.";
        }

        return null;
    }

    /**
     * Whether nobody else is in this workspace — leaving it then closes it for
     * good, since nobody is left who could let anybody back in.
     */
    public static function isAlone(User $user, Organization $organization): bool
    {
        return ! self::othersIn($user, $organization);
    }

    /**
     * Leave a workspace. Their roles, membership and phone sessions there go;
     * the account, its other workspaces and their employee record there stay —
     * the record is the company's history, not their access.
     *
     * @throws WorkspaceException
     */
    public static function leave(User $user, Organization $organization): void
    {
        $why = self::whyNotLeave($user, $organization);

        if ($why !== null) {
            throw new WorkspaceException($why);
        }

        self::remove($user, $organization);

        app(Tenancy::class)->runFor($organization, function () use ($user): void {
            ActivityLogger::log(
                event: 'left',
                description: "{$user->full_name} left the workspace",
                subject: $user,
                logName: 'user_management',
                subjectLabel: $user->full_name,
            );

            // Whoever looks after accounts here should know a seat emptied.
            Notifier::toPermission(
                'users.view',
                "{$user->full_name} left the workspace",
                "{$user->full_name} left the workspace and can no longer sign in to it. Their employee record stays.",
                url: '/system/users',
                category: 'account',
                actor: $user,
                except: [$user->id],
            );
        });
    }

    /**
     * Take somebody out of a workspace and nothing more: that workspace's roles
     * (named — a bare detach would clear their roles everywhere), the
     * membership, and the phone sessions bound to it. When it was the
     * workspace their sign-in landed in, their next one becomes it.
     *
     * Unguarded: {@see leave()} and {@see UserAccounts::archive()} decide
     * whether it may happen.
     */
    public static function remove(User $user, Organization $organization): void
    {
        app(Tenancy::class)->runFor($organization, function () use ($user, $organization): void {
            DB::transaction(function () use ($user, $organization): void {
                $wasDefault = (bool) $user->memberships()->where('organizations.id', $organization->id)->value('organization_user.is_default');

                $user->roles()->detach(Role::query()->pluck('id')->all());
                $user->memberships()->detach($organization->id);
                $user->tokens()->where('organization_id', $organization->id)->delete();

                if ($wasDefault) {
                    $next = $user->memberships()->orderBy('organization_user.id')->first();

                    if ($next !== null) {
                        $user->memberships()->updateExistingPivot($next->id, ['is_default' => true]);
                    }
                }
            });
        });

        $user->forgetCachedPermissions();
    }

    /**
     * The workspaces this person cannot walk away from — the ones where they
     * are the only active HR Manager and other people still work. Deleting
     * their account is refused while there are any.
     *
     * @return Collection<int, Organization>
     */
    public static function heldTogetherBy(User $user): Collection
    {
        return $user->memberships()
            ->orderBy('organizations.name')
            ->get()
            ->filter(fn (Organization $organization): bool => self::isLastManagerOfOthers($user, $organization))
            ->values();
    }

    /**
     * Whether this person is the workspace's only active HR Manager while
     * other active people are in it.
     */
    private static function isLastManagerOfOthers(User $user, Organization $organization): bool
    {
        if (! self::othersIn($user, $organization)) {
            return false;
        }

        $last = app(Tenancy::class)->runFor($organization, function () use ($user): bool {
            // Roles memoised under another tenant would answer for the wrong one.
            $user->forgetCachedPermissions();

            $role = Role::query()->where('name', Role::HR_MANAGER)->first();

            return $role !== null
                && $user->isSuperAdmin()
                && GrantRules::lastHolder($role, $user);
        });

        $user->forgetCachedPermissions();

        return $last;
    }

    /**
     * Whether anybody besides this person can still sign in to the workspace.
     */
    private static function othersIn(User $user, Organization $organization): bool
    {
        return User::query()
            ->inOrganization($organization)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->exists();
    }
}
