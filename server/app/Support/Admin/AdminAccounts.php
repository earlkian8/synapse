<?php

namespace App\Support\Admin;

use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Managing admin accounts (ADR 0085) — everything a super admin can do to
 * another admin, with the two rules that keep the portal governable:
 *
 *  - **Nobody acts on themselves here.** One's own password and sessions are
 *    managed from one's account page; one's own role, status, authenticator and
 *    existence are left to another super admin.
 *  - **There is always an active super admin.** Demoting, deactivating or
 *    deleting the last one is refused, checked under a row lock so two super
 *    admins cannot remove each other at the same moment.
 *
 * Whenever access changes, the target's sessions end at once: a role or status
 * is read on every request, but a password or authenticator reset must also
 * shut out whoever is signed in on the old one.
 */
class AdminAccounts
{
    /**
     * @param  array{name: string, email: string, role: string}  $data
     * @return array{0: Admin, 1: string} the admin and their one-time password
     */
    public function create(array $data, Admin $actor): array
    {
        $password = AdminPasswords::temporary();

        $admin = Admin::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => $password,
            'is_active' => true,
            'must_change_password' => true,
            'created_by' => $actor->id,
        ]);

        AdminAudit::record('admin_created', 'Created '.mb_strtolower($admin->roleLabel())." account for {$admin->name}", subject: $admin, properties: ['role' => $admin->role], subjectLabel: $this->label($admin));

        return [$admin, $password];
    }

    /**
     * @param  array{name: string, email: string, role: string}  $data
     */
    public function update(Admin $admin, array $data, Admin $actor): Admin
    {
        if ($data['role'] !== $admin->role) {
            $this->refuseSelf($admin, $actor, 'You cannot change your own role. Ask another super admin.');
        }

        return DB::transaction(function () use ($admin, $data): Admin {
            if ($admin->role === Admin::ROLE_SUPER_ADMIN && $data['role'] !== Admin::ROLE_SUPER_ADMIN) {
                $this->refuseLastSuperAdmin($admin, 'This is the only active super admin. Make someone else a super admin first.');
            }

            $admin->fill($data);
            $changes = collect($admin->getDirty())
                ->map(fn ($value, string $field): array => ['from' => $admin->getOriginal($field), 'to' => $value])
                ->all();
            $admin->save();

            if ($changes !== []) {
                AdminAudit::record('admin_updated', "Updated {$admin->name}'s account", subject: $admin, properties: ['changes' => $changes], subjectLabel: $this->label($admin));
            }

            return $admin;
        });
    }

    public function setActive(Admin $admin, bool $active, Admin $actor): Admin
    {
        if ($active === $admin->is_active) {
            return $admin;
        }

        $this->refuseSelf($admin, $actor, 'You cannot change your own status.');

        return DB::transaction(function () use ($admin, $active): Admin {
            if (! $active) {
                $this->refuseLastSuperAdmin($admin, 'This is the only active super admin. Make someone else a super admin first.');
            }

            $admin->forceFill(['is_active' => $active])->save();

            if (! $active) {
                $admin->revokeSessions();
            }

            AdminAudit::record(
                $active ? 'admin_activated' : 'admin_deactivated',
                ($active ? 'Activated ' : 'Deactivated ')."{$admin->name}'s account",
                subject: $admin,
                subjectLabel: $this->label($admin),
            );

            return $admin;
        });
    }

    /**
     * Issue a new one-time password; the admin chooses their own at next sign-in.
     */
    public function resetPassword(Admin $admin, Admin $actor): string
    {
        $this->refuseSelf($admin, $actor, 'Change your own password from your account page.');

        $password = AdminPasswords::temporary();

        $admin->forceFill(['password' => $password, 'must_change_password' => true])->save();
        $ended = $admin->revokeSessions();

        AdminAudit::record('admin_password_reset', "Reset {$admin->name}'s password", subject: $admin, properties: ['sessions_ended' => $ended], subjectLabel: $this->label($admin));

        return $password;
    }

    /**
     * Forget the admin's authenticator; their next sign-in sets up a new one.
     */
    public function resetTwoFactor(Admin $admin, Admin $actor): void
    {
        $this->refuseSelf($admin, $actor, 'Ask another super admin to reset your authenticator.');

        $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        $ended = $admin->revokeSessions();

        AdminAudit::record('admin_two_factor_reset', "Reset {$admin->name}'s authenticator", subject: $admin, properties: ['sessions_ended' => $ended], subjectLabel: $this->label($admin));
    }

    /**
     * Delete an account. Only a deactivated one, so deleting is always the second
     * of two deliberate steps.
     */
    public function delete(Admin $admin, Admin $actor): void
    {
        $this->refuseSelf($admin, $actor, 'You cannot delete your own account.');

        if ($admin->is_active) {
            throw ValidationException::withMessages(['admin' => 'Deactivate this admin before deleting the account.']);
        }

        $label = $this->label($admin);

        DB::transaction(function () use ($admin, $label): void {
            $admin->revokeSessions();

            AdminAudit::record('admin_deleted', "Deleted {$admin->name}'s account", subject: $admin, properties: ['role' => $admin->role], subjectLabel: $label);

            $admin->delete();
        });
    }

    private function refuseSelf(Admin $admin, Admin $actor, string $message): void
    {
        if ($admin->is($actor)) {
            throw ValidationException::withMessages(['admin' => $message]);
        }
    }

    /**
     * Refuse when `$admin` is the last active super admin. Call inside a
     * transaction: the active super admins' rows stay locked until it ends.
     */
    private function refuseLastSuperAdmin(Admin $admin, string $message): void
    {
        if (! $admin->isSuperAdmin() || ! $admin->is_active) {
            return;
        }

        // Locked rows rather than a COUNT(): Postgres will not lock an aggregate.
        $active = Admin::query()
            ->where('role', Admin::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->lockForUpdate()
            ->pluck('id');

        if ($active->reject(fn (int $id): bool => $id === $admin->id)->isEmpty()) {
            throw ValidationException::withMessages(['admin' => $message]);
        }
    }

    private function label(Admin $admin): string
    {
        return "{$admin->name} ({$admin->email})";
    }
}
