<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Permission;
use App\Models\User;
use App\Support\OrganizationProvisioner;
use App\Support\PermissionSyncer;
use App\Support\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and register a new account.
     *
     * Registration provisions a whole tenant: a new organisation, its built-in role
     * set, and the registrant as that organisation's owner (Super Admin). See ADR 0005.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            'organization_name' => ['required', 'string', 'max:255'],
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            // Make sure the global permission catalogue exists before wiring roles
            // (covers a fresh install where nobody has seeded yet).
            if (Permission::count() === 0) {
                PermissionSyncer::sync();
            }

            [$organization, $superAdmin] = OrganizationProvisioner::create($input['organization_name']);

            app(Tenancy::class)->set($organization);

            $user = User::create([
                'organization_id' => $organization->id,
                'first_name' => $input['first_name'],
                'middle_name' => $input['middle_name'] ?? null,
                'last_name' => $input['last_name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'is_active' => true,
            ]);

            $user->roles()->attach($superAdmin->id);

            return $user;
        });
    }
}
