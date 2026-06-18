<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Position;
use App\Models\Role;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Notifications\SystemNotification;
use App\Support\OrganizationProvisioner;
use App\Support\PermissionSyncer;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Mock data for **every module**, scoped to one account's organisation.
 *
 * Stands up (or reuses) the target user + their tenant, makes them Super Admin,
 * then runs every module seeder within that tenant — Company Setup, Employees,
 * Recruitment, Onboarding, Leave, Attendance, Payroll, Benefits, Performance,
 * Training, Awards and Events — and tops it off with the System surfaces the module
 * seeders don't touch: extra Users, an Activity-Log trail and in-app
 * Notifications.
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

        // System — a few more accounts so User Management has a roster.
        $this->seedUsers();

        // Org foundation first (departments, positions, schedules, a starter team).
        $this->call(OrganizationSeeder::class);

        // Guarantee a full roster before the per-employee modules run — the demo
        // employee set is only seeded when the tenant has none, so an account that
        // already has a stray employee would otherwise stay thin.
        $this->ensureEmployees(25);

        // The remaining operational / talent modules, in dependency order. Each is
        // idempotent and tenant-aware, and now sees the full roster.
        $this->call([
            RecruitmentSeeder::class,    // postings, applicants, applications, interviews
            OnboardingSeeder::class,     // programs + in-flight cases
            LeaveSeeder::class,          // leave types + balances + requests
            AttendanceSeeder::class,     // punches + daily records
            PayrollSeeder::class,        // statutory config + runs + payslips
            BenefitSeeder::class,        // plans + enrollments + contributions
            PerformanceSeeder::class,    // KPI criteria + periods + evaluations
            TrainingSeeder::class,       // programs + enrollments
            AwardSeeder::class,          // award types + recognitions
            EventSeeder::class,          // events / meetings + attendees
        ]);

        // System — Activity Logs + Notifications (the module seeders don't write these).
        $this->seedActivityLogs($user);
        $this->seedNotifications($user);
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

    /**
     * A small roster of additional accounts (one HR Manager, two Staff) so the
     * User Management + Roles surfaces have data. Idempotent.
     */
    private function seedUsers(): void
    {
        $accounts = [
            ['email' => 'mock.hr@synapse.test', 'first' => 'Hannah', 'last' => 'Reyes', 'role' => 'hr-manager'],
            ['email' => 'mock.staff1@synapse.test', 'first' => 'Sam', 'last' => 'Cruz', 'role' => 'staff'],
            ['email' => 'mock.staff2@synapse.test', 'first' => 'Jamie', 'last' => 'Lim', 'role' => 'staff'],
        ];

        foreach ($accounts as $account) {
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'first_name' => $account['first'],
                    'last_name' => $account['last'],
                    'password' => Hash::make('password'),
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );

            $role = Role::where('name', $account['role'])->first();

            if ($role) {
                $user->roles()->syncWithoutDetaching([$role->id]);
            }
        }
    }

    /**
     * A believable activity trail across the modules, attributed to the target
     * user. Only seeded when the tenant has no logs yet (so a real account's
     * history is never padded).
     */
    private function seedActivityLogs(User $user): void
    {
        if (ActivityLog::count() > 0) {
            return;
        }

        $entries = [
            ['employees', 'created', 'Created employee "Maria Santos"', 'Maria Santos'],
            ['recruitment', 'created', 'Posted a job opening "Software Engineer"', 'Software Engineer'],
            ['leave', 'approved', 'Approved a leave request', 'Leave request'],
            ['payroll', 'processed', 'Processed a payroll run', 'Payroll run'],
            ['benefits', 'created', 'Enrolled an employee in Maxicare HMO – Standard', 'Maxicare HMO – Standard'],
            ['performance', 'submitted', 'Submitted a performance evaluation', 'Performance evaluation'],
            ['training', 'created', 'Created training program "Leadership Essentials"', 'Leadership Essentials'],
            ['awards', 'created', 'Recognised an employee — Employee of the Month', 'Employee of the Month'],
            ['company-setup', 'updated', 'Updated company setup configuration', 'Company Setup'],
        ];

        foreach ($entries as $i => [$logName, $event, $description, $subjectLabel]) {
            $log = ActivityLog::create([
                'log_name' => $logName,
                'event' => $event,
                'description' => $description,
                'causer_id' => $user->id,
                'subject_label' => $subjectLabel,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'MockSeeder',
            ]);

            // Stagger the trail (created_at is not mass-assignable, so set it directly).
            $log->forceFill([
                'created_at' => now()->subDays($i),
                'updated_at' => now()->subDays($i),
            ])->save();
        }
    }

    /**
     * A handful of in-app notifications for the target user, in the same shape a
     * {@see SystemNotification} stores (so the bell + page render them). Written
     * directly to skip the mail / web-push channels. Only when the user has none.
     */
    private function seedNotifications(User $user): void
    {
        if ($user->notifications()->count() > 0) {
            return;
        }

        $items = [
            ['Welcome to SYNAPSE', 'Your workspace is ready — explore the modules from the sidebar.', '/dashboard', 'info', 'general', false],
            ['Payroll run finalized', 'The latest payroll run has been processed and is ready for review.', '/payroll', 'success', 'payroll', false],
            ['Leave request pending', 'A leave request is awaiting your approval.', '/leave', 'warning', 'leave', false],
            ['New recognition given', 'An Employee of the Month award was recorded.', '/awards', 'success', 'awards', true],
        ];

        foreach ($items as $i => [$title, $body, $url, $level, $category, $read]) {
            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => SystemNotification::class,
                'data' => [
                    'title' => $title,
                    'body' => $body,
                    'url' => $url,
                    'level' => $level,
                    'category' => $category,
                    'actor' => null,
                ],
                'read_at' => $read ? now() : null,
                'created_at' => now()->subHours($i * 6),
                'updated_at' => now()->subHours($i * 6),
            ]);
        }
    }
}
