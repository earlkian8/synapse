<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationProvisioner;
use App\Support\PermissionSyncer;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;

class RolePermissionSeeder extends Seeder
{
    /**
     * Sync the global permission catalogue, provision the current organisation's
     * built-in roles, and grant the seeded developer account Super Admin.
     *
     * Runs within the tenant bound by {@see DatabaseSeeder}; falls back to the
     * first organisation when invoked on its own.
     */
    public function run(): void
    {
        // 1. Sync the (global, code-defined) permissions table.
        PermissionSyncer::sync();

        $tenancy = app(Tenancy::class);

        $organization = $tenancy->organization() ?? Organization::first();

        if (! $organization) {
            return;
        }

        $tenancy->set($organization);

        // 2. Provision this organisation's built-in roles.
        $superAdmin = OrganizationProvisioner::provisionRoles($organization);

        // 3. Grant the seeded developer account Super Admin.
        User::where('email', 'dev@nexo.com')->first()
            ?->roles()->syncWithoutDetaching([$superAdmin->id]);
    }
}
