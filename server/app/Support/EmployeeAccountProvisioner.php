<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provisions the login account a new hire uses to sign in to the SYNAPSE mobile
 * app. Extracted so the recruitment hire bridge ({@see ApplicantHirer}) and any
 * future caller share one implementation (mirrors {@see OnboardingProvisioner}).
 *
 * An employee is linked to one {@see User} *identity*. Because a person can work
 * for several companies (ADR 0023), the identity is resolved by its globally-unique
 * email: if an account already exists (they're employed elsewhere, or a rehire) it
 * is reused and simply added as a member of this organisation; otherwise a new
 * account is created with a generated temporary password. Either way the employee
 * is granted the org's baseline `staff` role and linked back via
 * {@see Employee::$user_id}. Idempotent — an employee that already has an account is
 * returned untouched (with no password, since the existing secret is never knowable).
 */
class EmployeeAccountProvisioner
{
    /**
     * Ensure the employee has a login account, returning the account and the
     * plain-text temporary password when one was just generated.
     *
     * Returns `[null, null]` when the employee has no email to sign in with, and
     * `[$user, null]` when an account already existed.
     *
     * @return array{0: ?User, 1: ?string}
     */
    public static function provision(Employee $employee): array
    {
        if ($employee->user_id !== null) {
            return [$employee->user, null];
        }

        $email = trim((string) $employee->email);

        if ($email === '') {
            return [null, null];
        }

        // Resolve the global identity by email: reuse an existing account (the person
        // already works for another company, or a rehire), else create a new one.
        $user = User::where('email', $email)->first();
        $password = null;

        if (! $user) {
            $password = Str::password(12, symbols: false);

            $user = User::create([
                'first_name' => $employee->first_name,
                'middle_name' => $employee->middle_name,
                'last_name' => $employee->last_name,
                'suffix' => $employee->suffix,
                'email' => $email,
                'password' => Hash::make($password),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        }

        // Make the identity a member of this organisation (their first membership
        // becomes the login default), then grant the org's baseline staff role.
        if ($employee->organization !== null) {
            OrganizationProvisioner::addMember(
                $employee->organization,
                $user,
                default: ! $user->memberships()->exists(),
            );
        }

        $staff = Role::where('name', 'staff')->first();

        if ($staff) {
            $user->roles()->syncWithoutDetaching([$staff->id]);
        }

        // Link the account to the employee (the FK lives on the employees table).
        $employee->forceFill(['user_id' => $user->id])->save();

        return [$user, $password];
    }

    /**
     * Reset (or first-time provision) the employee's login password, returning the
     * account and a fresh plain-text temporary password to hand back to them.
     *
     * Ensures the account exists first (provisioning one for an employee who never
     * had it), then rotates the password. Returns `[null, null]` when the employee
     * has no email to sign in with.
     *
     * @return array{0: ?User, 1: ?string}
     */
    public static function resetPassword(Employee $employee): array
    {
        [$user, $password] = self::provision($employee);

        if (! $user) {
            return [null, null];
        }

        // A just-provisioned account already carries a fresh password.
        if ($password !== null) {
            return [$user, $password];
        }

        $password = Str::password(12, symbols: false);

        $user->forceFill([
            'password' => Hash::make($password),
            'password_changed_at' => null,
        ])->save();

        return [$user, $password];
    }
}
