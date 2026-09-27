<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\OrganizationProvisioner;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * The owner login the seeded workspace ships with. Apart from the mobile demo
     * employee ({@see self::MOBILE_EMPLOYEE_EMAIL}), everything else — the roster,
     * the applicants, the approvers — is data, not an account: alpha testers sign
     * in as this identity and see the whole system through it. Referenced by
     * {@see RolePermissionSeeder} so the Super Admin grant can never drift from it.
     */
    public const ACCOUNT_EMAIL = 'earlkian.dev@gmail.com';

    public const ACCOUNT_FIRST_NAME = 'Earl Kian';

    public const ACCOUNT_LAST_NAME = 'Bancayrin';

    /**
     * A plain staff login linked to its own roster line, for signing in to the
     * mobile app as an ordinary employee rather than as the workspace owner.
     */
    public const MOBILE_EMPLOYEE_EMAIL = 'earlkian8@gmail.com';

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

        $owner = User::firstOrCreate(
            ['email' => self::ACCOUNT_EMAIL],
            [
                'first_name' => self::ACCOUNT_FIRST_NAME,
                'middle_name' => null,
                'last_name' => self::ACCOUNT_LAST_NAME,
                'password' => Hash::make('password'),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        // The owner is a member of (and lands in) this organisation by default (ADR 0023).
        OrganizationProvisioner::addMember($organization, $owner, default: true);

        // Permission catalogue, this organisation's built-in roles, and the
        // Super Admin grant for the owner account.
        $this->call(RolePermissionSeeder::class);

        // Organisation foundation (departments, positions, schedules) + employees.
        $this->call(OrganizationSeeder::class);

        // The demo employee login for the mobile app — added before the module
        // seeders so it picks up attendance, leave, awards, etc. like the rest.
        $this->seedMobileEmployee($organization);

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

        // A second company so the workspace switcher is demoable end-to-end:
        // the owner account belongs to both and can switch between them.
        $this->seedSecondaryTenant($owner, $organization);
    }

    /**
     * A staff account linked to its own employee record (Earl Kian A. Bancayrin,
     * Software Engineer in IT), so the mobile self-service app can be signed in
     * to as a regular employee. Idempotent.
     */
    private function seedMobileEmployee(Organization $organization): void
    {
        $user = User::firstOrCreate(
            ['email' => self::MOBILE_EMPLOYEE_EMAIL],
            [
                'first_name' => self::ACCOUNT_FIRST_NAME,
                'middle_name' => 'A.',
                'last_name' => self::ACCOUNT_LAST_NAME,
                'password' => Hash::make('password'),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $employee = Employee::where('email', self::MOBILE_EMPLOYEE_EMAIL)->first();

        if ($employee === null) {
            $it = Department::where('code', 'IT')->first();

            $employee = Employee::factory()->regular()->create([
                'first_name' => self::ACCOUNT_FIRST_NAME,
                'middle_name' => 'A.',
                'last_name' => self::ACCOUNT_LAST_NAME,
                'suffix' => null,
                'gender' => 'male',
                'email' => self::MOBILE_EMPLOYEE_EMAIL,
                'department_id' => $it?->id,
                'position_id' => Position::where('title', 'Software Engineer')->where('department_id', $it?->id)->value('id'),
                'manager_id' => $it?->head_id,
                'work_schedule_id' => WorkSchedule::where('name', 'Day Shift')->value('id'),
            ]);
        }

        // Membership + the Staff role + the user ↔ employee link, the same way an
        // accepted invitation admits someone (ADR 0026).
        OrganizationProvisioner::admit($organization, $user, $employee, Role::STAFF);
    }

    /**
     * Stand up a small second tenant the owner account also belongs to, so the
     * one-identity-many-companies switching (ADR 0023) can be demonstrated. Gives it
     * its own foundation and links the account to an employee there too.
     */
    private function seedSecondaryTenant(User $owner, Organization $primary): void
    {
        if (Organization::where('name', 'SYNAPSE Labs')->exists()) {
            return;
        }

        [$labs, $labsSuperAdmin] = OrganizationProvisioner::create('SYNAPSE Labs');

        OrganizationProvisioner::addMember($labs, $owner); // a second, non-default membership
        $owner->roles()->syncWithoutDetaching([$labsSuperAdmin->id]);

        $tenancy = app(Tenancy::class);
        $tenancy->set($labs);

        // Foundation (departments, positions, schedules, employees) so switching into
        // the second company shows a populated workspace rather than an empty one.
        $this->call(OrganizationSeeder::class);

        // Link the account to an employee here too, so the mobile app resolves a self
        // record in either workspace (one identity → one employee per organisation).
        $employee = Employee::whereNull('user_id')->orderBy('id')->first();

        if ($employee) {
            $employee->forceFill(['user_id' => $owner->id])->save();
        }

        $tenancy->set($primary); // restore the primary tenant for anything after.
    }
}
