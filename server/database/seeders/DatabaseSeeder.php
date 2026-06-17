<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationProvisioner;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database for a single demo organisation (tenant).
     *
     * Reuses the "Default Organization" created during the multi-tenancy migration
     * when present, so re-seeding an existing install stays consistent.
     */
    public function run(): void
    {
        $organization = Organization::first()
            ?? OrganizationProvisioner::create('SYNAPSE Demo Co')[0];

        // Bind the tenant so every scoped model below lands in this organisation.
        app(Tenancy::class)->set($organization);

        User::firstOrCreate(
            ['email' => 'dev@synapse.com'],
            [
                'first_name' => 'Test',
                'middle_name' => null,
                'last_name' => 'User',
                'password' => Hash::make('password'),
                'is_active' => true,
            ],
        );

        // Permission catalogue, this organisation's built-in roles, and the
        // Super Admin grant for dev@synapse.com.
        $this->call(RolePermissionSeeder::class);

        // Organisation foundation (departments, positions, schedules) + employees.
        $this->call(OrganizationSeeder::class);

        // Recruitment pipeline (postings, applicants, applications, interviews).
        $this->call(RecruitmentSeeder::class);

        // Onboarding (default program + a few in-flight cases).
        $this->call(OnboardingSeeder::class);

        // Leave (default types + demo balances and requests).
        $this->call(LeaveSeeder::class);

        // Attendance (~6 weeks of demo punches across the team).
        $this->call(AttendanceSeeder::class);

        // Payroll (statutory config + recent runs processed from attendance).
        $this->call(PayrollSeeder::class);
    }
}
