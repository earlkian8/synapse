<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Position;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\OrganizationProvisioner;
use App\Support\PermissionSyncer;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Mock data for **every module**, scoped to one account's organisation.
 *
 * Stands up (or reuses) the target user + their tenant, makes them Super Admin,
 * then runs every module seeder within that tenant — Company Setup, Employees,
 * Recruitment, Onboarding, Leave, Attendance, Performance,
 * Training, Awards, Events and Offboarding — plus the per-employee profile records
 * ({@see EmployeeProfileSeeder}: documents, certifications, promotions) and the
 * System surfaces ({@see SystemSeeder}: extra Users, an Activity-Log trail and
 * in-app Notifications) the operational module seeders don't touch.
 *
 * Idempotent and standalone — run on its own, it does not touch the demo tenant:
 *
 *     php artisan db:seed --class=MockSeeder
 *
 * To target a different account, change {@see self::EMAIL} (and the org name used
 * when the account does not yet exist).
 */
class MockSeeder extends Seeder
{
    /** The account whose organisation receives the mock data. */
    private const EMAIL = 'earlkian.dev@gmail.com';

    /** Organisation name used only when the account does not exist yet. */
    private const ORG_NAME = 'Earl Kian Workspace';

    public function run(): void
    {
        [$organization, $user] = $this->resolveTenant();

        // Bind the tenant so every scoped model below lands in this organisation.
        app(Tenancy::class)->set($organization);

        // System — permissions catalogue, the org's built-in roles, Super Admin grant.
        PermissionSyncer::sync();
        $superAdmin = OrganizationProvisioner::provisionRoles($organization);
        $user->roles()->syncWithoutDetaching([$superAdmin->id]);

        // Org foundation first (departments, positions, schedules, a starter team).
        $this->call(OrganizationSeeder::class);

        // Guarantee a full roster before the per-employee modules run — the demo
        // employee set is only seeded when the tenant has none, so an account that
        // already has a stray employee would otherwise stay thin.
        $this->ensureEmployees(25);

        // The remaining operational / talent modules, in dependency order. Each is
        // idempotent and tenant-aware, and now sees the full roster.
        $this->call([
            HolidaySeeder::class,          // PH statutory holiday calendar
            RecruitmentSeeder::class,      // postings, applicants, applications, interviews
            OnboardingSeeder::class,       // programs + in-flight cases
            LeaveSeeder::class,            // leave types + balances + requests
            AttendanceSeeder::class,       // punches + daily records
            PerformanceSeeder::class,      // KPI criteria + periods + evaluations
            TrainingSeeder::class,         // programs + enrollments
            AwardSeeder::class,            // award types + recognitions
            EventSeeder::class,            // events / meetings + attendees
            OffboardingSeeder::class,      // exits + clearance checklists
            EmployeeProfileSeeder::class,  // documents, certifications, promotions
            SystemSeeder::class,           // extra users, activity logs, notifications
        ]);
    }

    /**
     * Find the target account and its organisation, creating both when the
     * account does not exist yet (so this runs on a clean database too).
     *
     * @return array{0: Organization, 1: User}
     */
    private function resolveTenant(): array
    {
        $user = User::where('email', self::EMAIL)->first();

        if ($user && $user->organization_id) {
            return [Organization::findOrFail($user->organization_id), $user];
        }

        // No account yet — provision a fresh organisation (with its roles) and the user.
        [$organization, $superAdmin] = OrganizationProvisioner::create(self::ORG_NAME);

        app(Tenancy::class)->set($organization);

        $user = User::create([
            'first_name' => 'Earl Kian',
            'last_name' => 'Bancayrin',
            'email' => self::EMAIL,
            'password' => Hash::make('password'),
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $user->roles()->syncWithoutDetaching([$superAdmin->id]);

        return [$organization, $user];
    }

    /**
     * Top the employee roster up to a target headcount, assigning each new hire a
     * department, a position within it, and a work schedule. No-op once the target
     * is met. Relies on the departments / positions / schedules seeded by
     * {@see OrganizationSeeder}.
     */
    private function ensureEmployees(int $target): void
    {
        $missing = $target - Employee::count();

        if ($missing <= 0) {
            return;
        }

        $departments = Department::query()->whereHas('positions')->get();
        $scheduleIds = WorkSchedule::pluck('id');

        if ($departments->isEmpty() || $scheduleIds->isEmpty()) {
            return;
        }

        Employee::factory()->count($missing)->create()->each(function (Employee $employee) use ($departments, $scheduleIds): void {
            $department = $departments->random();

            $employee->update([
                'department_id' => $department->id,
                'position_id' => Position::where('department_id', $department->id)->inRandomOrder()->value('id'),
                'work_schedule_id' => $scheduleIds->random(),
            ]);
        });

        // A stable, gender-matched demo portrait for anyone still missing one.
        Employee::whereNull('photo')->get()->each(function (Employee $employee): void {
            $bucket = $employee->gender === 'female' ? 'women' : 'men';
            $employee->update(['photo' => "https://randomuser.me/api/portraits/{$bucket}/".($employee->id % 100).'.jpg']);
        });
    }
}
