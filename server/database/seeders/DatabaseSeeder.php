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
                'email_verified_at' => now(),
            ],
        );

        // Permission catalogue, this organisation's built-in roles, and the
        // Super Admin grant for dev@synapse.com.
        $this->call(RolePermissionSeeder::class);

        // Organisation foundation (departments, positions, schedules) + employees.
        $this->call(OrganizationSeeder::class);

        // Holiday calendar (PH statutory holidays) — read by Leave.
        $this->call(HolidaySeeder::class);

        // Recruitment pipeline (postings, applicants, applications, interviews).
        $this->call(RecruitmentSeeder::class);

        // Onboarding (default program + a few in-flight cases).
        $this->call(OnboardingSeeder::class);

        // Leave (default types + demo balances and requests).
        $this->call(LeaveSeeder::class);

        // Attendance (~6 weeks of demo punches across the team).
        $this->call(AttendanceSeeder::class);

        // Performance (KPI criteria + review cycles + scored evaluations).
        $this->call(PerformanceSeeder::class);

        // Training (programs across the lifecycle + employee enrollments).
        $this->call(TrainingSeeder::class);

        // Awards (recognition types + a spread of employee awards).
        $this->call(AwardSeeder::class);

        // Events & Meetings (a spread across the lifecycle + invitees).
        $this->call(EventSeeder::class);

        // Offboarding (exits across the lifecycle + clearance checklists).
        $this->call(OffboardingSeeder::class);

        // Per-employee profile records (documents, certifications, promotions)
        // that the operational module seeders above don't produce.
        $this->call(EmployeeProfileSeeder::class);

        // System surfaces (extra login accounts, an activity-log trail, in-app
        // notifications) so User Management, Activity Logs and the bell have data.
        $this->call(SystemSeeder::class);
    }
}
