<?php

namespace App\Support\Users;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\Role;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use App\Support\OrganizationProvisioner;
use App\Support\Roles\GrantRules;
use App\Support\Roles\RoleException;
use App\Support\Roles\RoleWorkflow;
use App\Support\Tenancy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Everything that changes a sign-in account from inside a workspace: adding
 * someone, their details, whether they can sign in, their password, archiving,
 * restoring and deleting them (ADR 0057).
 *
 * The Users screen (its forms, row actions and bulk actions), the Trash Bin and
 * the assistant all come through here. Refusals are {@see UserAccountException}
 * (and {@see RoleException} for roles), worded to be shown as they are.
 *
 * A user is an identity shared across workspaces (ADR 0023), so two rules keep
 * one company from reaching into another through it:
 *
 * - **An account that belongs to another workspace too is its holder's.** This
 *   workspace manages its own membership and roles; the name, email, photo,
 *   password and active state are left to the person, and archiving them here
 *   *removes them from this workspace* — it does not lock them out of the
 *   others. Before this, any company could add an address, reset its password
 *   and sign in as that person everywhere.
 * - **Nobody changes the account of someone with more access** than they have
 *   ({@see GrantRules::outranks()}): resetting an HR Manager's password is the
 *   same escalation as making yourself one.
 *
 * And nobody deactivates, archives or deletes their own account from here.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class UserAccounts
{
    /** The fields of an account that are its holder's identity. */
    private const PROFILE = ['first_name', 'middle_name', 'last_name', 'suffix', 'email', 'phone_number', 'is_active'];

    public function __construct(private readonly RoleWorkflow $roles) {}

    /**
     * Whether this account belongs to any workspace besides the current one.
     */
    public static function isShared(User $user): bool
    {
        $current = app(Tenancy::class)->id();

        return $current === null
            ? $user->memberships()->count() > 1
            : $user->memberships()->where('organizations.id', '!=', $current)->exists();
    }

    /**
     * Why this person may not change the account's identity or access, or null
     * when they may.
     *
     * @param  string  $what  What would change, for the message: "their details".
     */
    public static function whyNotChange(User $user, User $actor, string $what): ?string
    {
        if (self::isShared($user)) {
            return "{$user->full_name} also belongs to another workspace, so {$what} are theirs to manage, not this workspace's. Only their roles here can be changed — or archive them to remove them from this workspace.";
        }

        if (GrantRules::outranks($user, $actor)) {
            return "{$user->full_name} has access you don't, so only someone with at least their access can change {$what}.";
        }

        return null;
    }

    /**
     * Why this person may not delete the account for good, or null when they
     * may: never their own, never one another workspace shares, never one with
     * more access than theirs.
     */
    public static function whyNotPurge(User $user, User $actor): ?string
    {
        if ($user->is($actor)) {
            return 'You cannot delete your own account.';
        }

        if (self::isShared($user)) {
            return "{$user->full_name}'s account also belongs to another workspace, so it cannot be deleted from here.";
        }

        if (GrantRules::outranks($user, $actor)) {
            return "{$user->full_name} has access you don't, so only someone with at least their access can delete them.";
        }

        return null;
    }

    // ── Adding someone ───────────────────────────────────────────────────────

    /**
     * Add somebody to this workspace. An email that already has an account —
     * they work for another company — links that account in rather than making
     * a second one. Roles are given only when `$roleIds` is not null (the actor
     * may assign roles) and pass {@see GrantRules}.
     *
     * @param  array<string, mixed>  $profile  Name parts, email, phone, is_active, and (screen only) password.
     * @param  list<int|string>|null  $roleIds
     * @return array{user: User, linked: bool, verification_sent: bool}
     *
     * @throws UserAccountException|RoleException
     */
    public function create(array $profile, ?array $roleIds, User $actor, ?UploadedFile $photo = null, string $channel = ''): array
    {
        $organization = app(Tenancy::class)->organization();
        $existing = User::withTrashed()->where('email', Str::lower(trim((string) $profile['email'])))->first();

        if ($existing !== null && $existing->isMemberOf($organization)) {
            throw new UserAccountException($existing->trashed()
                ? 'That email belongs to an archived user of this organisation — restore them from the Trash Bin.'
                : 'That email already belongs to a user in this organisation.');
        }

        if ($existing?->trashed()) {
            throw new UserAccountException('That email cannot be used for a new account.');
        }

        $plan = $roleIds === null ? null : $this->roles->plan($existing, $roleIds, $actor);

        if ($existing !== null) {
            OrganizationProvisioner::addMember($organization, $existing);

            if ($plan !== null) {
                $this->roles->apply($existing, $plan, $actor);
            }

            Notifier::toUser(
                $existing,
                "You've been added to {$organization->name}",
                "Your SYNAPSE account now has access to {$organization->name}. Switch to it from the account menu.",
                url: '/dashboard',
                level: 'success',
                category: 'account',
                actor: $actor,
            );

            $this->log('created', "Added existing user {$existing->full_name} to the organisation{$channel}", $existing);

            return ['user' => $existing, 'linked' => true, 'verification_sent' => false];
        }

        $user = DB::transaction(function () use ($profile, $photo, $organization, $plan, $actor): User {
            $user = new User(Arr::only($profile, self::PROFILE));

            if (filled($profile['password'] ?? null)) {
                $user->password = $profile['password'];
                $user->password_changed_at = now();
            }

            if ($photo !== null) {
                $user->profile_photo = $photo->store('profile-photos', 'public');
            }

            $user->save();

            // The new identity's first (and so default) membership is this organisation.
            OrganizationProvisioner::addMember($organization, $user, default: true);

            if ($plan !== null) {
                $this->roles->apply($user, $plan, $actor);
            }

            return $user;
        });

        // New accounts start unverified — email a confirmation code so the holder
        // proves ownership of the address before they can sign in.
        $sent = $this->sendVerification($user);

        Notifier::toUser(
            $user,
            'Welcome to SYNAPSE',
            'Your account has been created. Please check your inbox to verify your email address, then sign in to get started.',
            url: '/dashboard',
            level: 'success',
            category: 'account',
            actor: $actor,
        );

        $this->log('created', "Created user {$user->full_name}{$channel}", $user);

        return ['user' => $user, 'linked' => false, 'verification_sent' => $sent];
    }

    // ── Changing someone ─────────────────────────────────────────────────────

    /**
     * Change an account's details and — when `$roleIds` is not null — its
     * roles in this workspace. Details are the holder's identity, so they are
     * refused on a shared or higher-ranked account ({@see whyNotChange()});
     * roles alone may still change there. Everything is checked before
     * anything is saved.
     *
     * @param  array<string, mixed>  $profile
     * @param  list<int|string>|null  $roleIds
     * @return array{email_changed: bool}
     *
     * @throws UserAccountException|RoleException
     */
    public function update(User $user, array $profile, ?array $roleIds, User $actor, ?UploadedFile $photo = null, bool $removePhoto = false, string $channel = ''): array
    {
        $probe = (clone $user)->fill(Arr::only($profile, self::PROFILE));
        $touched = array_keys(Arr::only($probe->getDirty(), self::PROFILE));

        if ($photo !== null || ($removePhoto && $user->profile_photo !== null)) {
            $touched[] = 'profile_photo';
        }

        if ($touched !== []) {
            $why = self::whyNotChange($user, $actor, 'their name, email, photo and sign-in');

            if ($why !== null) {
                throw new UserAccountException($why);
            }
        }

        if ($probe->isDirty('is_active') && ! $probe->is_active && $user->is($actor)) {
            throw new UserAccountException('You cannot deactivate your own account.');
        }

        $plan = $roleIds === null ? null : $this->roles->plan($user, $roleIds, $actor);

        $user->fill(Arr::only($profile, self::PROFILE));

        // A changed email must be re-confirmed: drop the verified state now and
        // send a fresh confirmation code to the new address after saving.
        $emailChanged = $user->isDirty('email');
        $deactivated = $user->isDirty('is_active') && ! $user->is_active;

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        if ($removePhoto || $photo !== null) {
            $this->deletePhoto($user);
        }

        if ($photo !== null) {
            $user->profile_photo = $photo->store('profile-photos', 'public');
        }

        $changed = array_values(array_diff(array_keys($user->getDirty()), ['updated_at']));

        $roles = DB::transaction(function () use ($user, $plan, $actor): array {
            $user->save();

            return $plan === null ? [] : $this->roles->apply($user, $plan, $actor);
        });

        if ($deactivated) {
            $this->endMobileSessions($user);
        }

        if ($emailChanged) {
            $this->sendVerification($user);
        }

        $this->log('updated', "Updated user {$user->full_name}{$channel}", $user, array_filter([
            'changed' => $changed,
            'attached' => $roles['attached'] ?? [],
            'detached' => $roles['detached'] ?? [],
        ]));

        return ['email_changed' => $emailChanged];
    }

    /**
     * Let an account sign in, or stop it. A deactivated account is signed out
     * of the web on its next request and its phone sessions end now.
     *
     * @throws UserAccountException
     */
    public function setActive(User $user, bool $active, User $actor, string $channel = ''): void
    {
        $this->guardStatus($user, $active, $actor);

        $user->update(['is_active' => $active]);

        if (! $active) {
            $this->endMobileSessions($user);
        }

        $this->log($active ? 'activated' : 'deactivated', ($active ? 'Activated user ' : 'Deactivated user ').$user->full_name.$channel, $user);
    }

    /**
     * Set a new password for somebody. The screen's alone — a password is never
     * handed to the assistant, which would send it to a model and keep it in
     * the conversation.
     *
     * @throws UserAccountException
     */
    public function resetPassword(User $user, string $password, User $actor): void
    {
        $why = self::whyNotChange($user, $actor, 'their password and sign-in');

        if ($why !== null) {
            throw new UserAccountException($why);
        }

        $user->update(['password' => $password, 'password_changed_at' => now()]);

        // Whoever held the old password is signed out of the app.
        $this->endMobileSessions($user);

        $this->log('password_reset', "Reset password for {$user->full_name}", $user);
    }

    /**
     * Send the email-confirmation code again to an unconfirmed address.
     *
     * @throws UserAccountException
     */
    public function resendVerification(User $user, string $channel = ''): void
    {
        if ($user->hasVerifiedEmail()) {
            throw new UserAccountException('This email address is already verified.');
        }

        if (! $this->sendVerification($user)) {
            throw new UserAccountException('The verification email could not be sent. Please try again.');
        }

        $this->log('verification_sent', "Resent the email verification link to {$user->full_name}{$channel}", $user);
    }

    // ── Archiving, restoring, deleting ───────────────────────────────────────

    /**
     * Archive somebody — or, when their account belongs to another workspace
     * too, remove them from this one: their roles and membership here go, the
     * account and its other workspaces stay. Returns which it was: "archived"
     * or "removed".
     *
     * @throws UserAccountException
     */
    public function archive(User $user, User $actor, string $channel = ''): string
    {
        $outcome = $this->archiveQuietly($user, $actor);

        $this->log($outcome, $outcome === 'removed'
            ? "Removed user {$user->full_name} from the organisation{$channel}"
            : "Archived user {$user->full_name}{$channel}", $user);

        return $outcome;
    }

    /**
     * Bring an archived account back.
     */
    public function restore(User $user, string $channel = ''): void
    {
        $user->restore();

        $this->log('restored', "Restored user {$user->full_name}{$channel}", $user);
    }

    /**
     * Delete an archived or current account for good.
     *
     * @throws UserAccountException
     */
    public function forceDelete(User $user, User $actor, string $channel = ''): void
    {
        $label = $user->full_name;
        $this->forceDeleteQuietly($user, $actor);

        ActivityLogger::log(
            event: 'deleted',
            description: "Permanently deleted user {$label}{$channel}",
            logName: 'user_management',
            subjectLabel: $label,
        );
    }

    /**
     * An archived account of this workspace, by id.
     */
    public static function findArchived(int $id): ?User
    {
        return User::onlyTrashed()->inCurrentOrganization()->find($id);
    }

    // ── Many at once ─────────────────────────────────────────────────────────

    /**
     * Apply one of the Users screen's bulk actions to the given accounts of this
     * workspace. Each account passes the same rules as its single-row action;
     * the ones that do not are skipped, and the first reason is returned. One
     * summary is recorded for the sweep.
     *
     * @param  list<int>  $ids
     * @return array{affected: int, skipped: int, reason: string|null}
     *
     * @throws RoleException When the role may not be given at all.
     */
    public function bulk(string $action, array $ids, User $actor, ?Role $role = null): array
    {
        if ($action === 'assign-role' && $role !== null) {
            $why = GrantRules::whyNotGive($actor, $role);

            if ($why !== null) {
                throw new RoleException($why);
            }
        }

        $query = match ($action) {
            'restore' => User::onlyTrashed(),
            'delete' => User::withTrashed(),
            default => User::query(),
        };

        $affected = 0;
        $skipped = 0;
        $reason = null;

        foreach ($query->inCurrentOrganization()->whereKey($ids)->get() as $user) {
            try {
                $changed = match ($action) {
                    'activate', 'deactivate' => $this->setActiveQuietly($user, $action === 'activate', $actor),
                    'archive' => (bool) $this->archiveQuietly($user, $actor),
                    'restore' => $user->restore(),
                    'delete' => $this->forceDeleteQuietly($user, $actor),
                    'assign-role' => $this->attachQuietly($user, $role),
                };
            } catch (UserAccountException $e) {
                $skipped++;
                $reason ??= $e->getMessage();

                continue;
            }

            $affected += $changed ? 1 : 0;
        }

        ActivityLogger::log(
            event: self::bulkEvent($action),
            description: 'Bulk '.self::bulkEvent($action)." {$affected} ".Str::plural('user', $affected),
            properties: ['action' => $action, 'count' => $affected, 'skipped' => $skipped, 'ids' => array_values($ids)],
            logName: 'user_management',
        );

        return ['affected' => $affected, 'skipped' => $skipped, 'reason' => $reason];
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * @throws UserAccountException
     */
    private function guardStatus(User $user, bool $active, User $actor): void
    {
        if (! $active && $user->is($actor)) {
            throw new UserAccountException('You cannot deactivate your own account.');
        }

        if ((bool) $user->is_active === $active) {
            throw new UserAccountException("{$user->full_name} is already ".($active ? 'active' : 'inactive').'.');
        }

        $why = self::whyNotChange($user, $actor, 'whether they can sign in');

        if ($why !== null) {
            throw new UserAccountException($why);
        }
    }

    /**
     * @throws UserAccountException
     */
    private function setActiveQuietly(User $user, bool $active, User $actor): bool
    {
        if ((bool) $user->is_active === $active) {
            return false;
        }

        $this->guardStatus($user, $active, $actor);
        $user->update(['is_active' => $active]);

        if (! $active) {
            $this->endMobileSessions($user);
        }

        return true;
    }

    /**
     * @return 'archived'|'removed'
     *
     * @throws UserAccountException
     */
    private function archiveQuietly(User $user, User $actor): string
    {
        if ($user->is($actor)) {
            throw new UserAccountException('You cannot archive your own account.');
        }

        if (GrantRules::outranks($user, $actor)) {
            throw new UserAccountException("{$user->full_name} has access you don't, so only someone with at least their access can archive them.");
        }

        if (self::isShared($user)) {
            $this->removeFromWorkspace($user);

            return 'removed';
        }

        $user->delete();
        $this->endMobileSessions($user);

        return 'archived';
    }

    /**
     * @throws UserAccountException
     */
    private function forceDeleteQuietly(User $user, User $actor): bool
    {
        $why = self::whyNotPurge($user, $actor);

        if ($why !== null) {
            throw new UserAccountException($why);
        }

        $this->deletePhoto($user);

        return (bool) $user->forceDelete();
    }

    private function attachQuietly(User $user, ?Role $role): bool
    {
        if ($role === null || $user->roles()->whereKey($role->id)->exists()) {
            return false;
        }

        $user->roles()->attach($role->id);
        $user->forgetCachedPermissions();

        return true;
    }

    /**
     * Take somebody out of this workspace and nothing more: this workspace's
     * roles (named — a bare detach would clear their roles everywhere), the
     * membership, and the phone sessions bound to this workspace. When this
     * was the workspace their sign-in landed in, their next one becomes it.
     */
    private function removeFromWorkspace(User $user): void
    {
        $organizationId = app(Tenancy::class)->id();

        DB::transaction(function () use ($user, $organizationId): void {
            $wasDefault = (bool) $user->memberships()->where('organizations.id', $organizationId)->value('organization_user.is_default');

            $user->roles()->detach(Role::query()->pluck('id')->all());
            $user->memberships()->detach($organizationId);
            $user->tokens()->where('organization_id', $organizationId)->delete();

            if ($wasDefault) {
                $next = $user->memberships()->orderBy('organization_user.id')->first();

                if ($next !== null) {
                    $user->memberships()->updateExistingPivot($next->id, ['is_default' => true]);
                }
            }
        });

        $user->forgetCachedPermissions();
    }

    /**
     * Sign the account out of the phone app. The web session ends on its next
     * request ({@see EnsureAccountIsActive}).
     */
    private function endMobileSessions(User $user): void
    {
        $user->tokens()->delete();
    }

    /**
     * Email the confirmation code. Sent synchronously so delivery doesn't depend
     * on a running queue worker — with verification enforced, a silently
     * dropped email would lock the user out. Transport failures are reported,
     * never thrown, so a mail outage never breaks the change itself.
     */
    private function sendVerification(User $user): bool
    {
        if ($user->hasVerifiedEmail()) {
            return false;
        }

        try {
            $user->sendEmailVerificationNotification();

            return true;
        } catch (\Throwable $e) {
            report($e);

            return false;
        }
    }

    private function deletePhoto(User $user): void
    {
        if ($user->profile_photo) {
            Storage::disk('public')->delete($user->profile_photo);
            $user->profile_photo = null;
        }
    }

    private static function bulkEvent(string $action): string
    {
        return match ($action) {
            'activate' => 'activated',
            'deactivate' => 'deactivated',
            'archive' => 'archived',
            'restore' => 'restored',
            'delete' => 'deleted',
            default => 'updated',
        };
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(string $event, string $description, User $user, array $properties = []): void
    {
        ActivityLogger::log(
            event: $event,
            description: $description,
            subject: $user,
            properties: $properties,
            logName: 'user_management',
            subjectLabel: $user->full_name,
        );
    }
}
