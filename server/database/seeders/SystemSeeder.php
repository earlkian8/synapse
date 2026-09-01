<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\Tenancy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * The System surfaces the operational module seeders don't touch: a believable
 * Activity-Log trail and a few in-app Notifications, both attributed to the
 * tenant's one account.
 *
 * The workspace ships with a **single login** ({@see DatabaseSeeder::ACCOUNT_EMAIL}).
 * User Management is demoable through it plus the invitation and join-code flows
 * (ADR 0026) — seeding extra fake logins would only put credentials nobody owns
 * in front of alpha testers.
 *
 * Tenant-aware and idempotent — runs within the tenant bound by the calling
 * seeder, and falls back to the first organisation when invoked on its own.
 */
class SystemSeeder extends Seeder
{
    public function run(): void
    {
        $tenancy = app(Tenancy::class);

        if (! $tenancy->check()) {
            $organization = Organization::first();

            if (! $organization) {
                return;
            }

            $tenancy->set($organization);
        }

        // The trail is always attributed to the account that owns the workspace.
        $primaryUser = User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', Role::SUPER_ADMIN))
            ->orderBy('id')
            ->first()
            ?? User::query()->orderBy('id')->first();

        if (! $primaryUser) {
            return;
        }

        // Link the login to an employee record so the mobile app (self-service
        // DTR, leave, awards, profile) is demoable on first sign-in.
        $this->linkEmployeeAccount($primaryUser);

        $this->seedActivityLogs($primaryUser);
        $this->seedNotifications($primaryUser);
    }

    /**
     * Give the account a linked Employee so it resolves a self record in the
     * mobile API. Idempotent: an account that already has one is left alone.
     */
    private function linkEmployeeAccount(User $user): void
    {
        if ($user->employee()->exists()) {
            return;
        }

        $employee = Employee::whereNull('user_id')->orderBy('id')->first();

        if ($employee) {
            $employee->forceFill(['user_id' => $user->id])->save();
        }
    }

    /**
     * A believable activity trail across the modules, attributed to the primary
     * account. Only seeded when the tenant has no logs yet (so a real account's
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
            ['performance', 'submitted', 'Submitted a performance evaluation', 'Performance evaluation'],
            ['training', 'created', 'Created training program "Leadership Essentials"', 'Leadership Essentials'],
            ['awards', 'created', 'Recognised an employee — Employee of the Month', 'Employee of the Month'],
            ['events', 'created', 'Scheduled "Company-wide Town Hall"', 'Company-wide Town Hall'],
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
                'user_agent' => 'SystemSeeder',
            ]);

            // Stagger the trail (created_at is not mass-assignable, so set it directly).
            $log->forceFill([
                'created_at' => now()->subDays($i),
                'updated_at' => now()->subDays($i),
            ])->save();
        }
    }

    /**
     * A handful of in-app notifications for the primary account, in the same
     * shape a {@see SystemNotification} stores (so the bell + page render them).
     * Written directly to skip the mail / web-push channels. Only when the user
     * has none.
     */
    private function seedNotifications(User $user): void
    {
        if ($user->notifications()->count() > 0) {
            return;
        }

        $items = [
            ['Welcome to SYNAPSE', 'Your workspace is ready — explore the modules from the sidebar.', '/dashboard', 'info', 'general', false],
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
