<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * The canonical catalogue of every permission in the system.
 *
 * This class — not the database — is the single source of truth for which
 * permissions exist. Gates are defined from it at boot, and the `permissions`
 * table is synced from it (see PermissionRegistrar / the seeder). To add a
 * permission to a module, add it here and re-sync.
 */
class PermissionRegistry
{
    /**
     * Permissions keyed by their UI group, then by permission name => label.
     *
     * @var array<string, array<string, string>>
     */
    public const GROUPS = [
        'Employee Management' => [
            'employees.view' => 'View employees',
            'employees.create' => 'Create employees',
            'employees.update' => 'Edit employees',
            'employees.delete' => 'Archive employees',
            'employees.restore' => 'Restore archived employees',
            'employees.force-delete' => 'Permanently delete employees',
            'employees.export' => 'Export employees',
            'employees.manage-documents' => 'Manage employee documents & records',
            'employees.invite' => 'Invite employees to the app & review join requests',
        ],
        'Recruitment' => [
            'recruitment.view' => 'View recruitment',
            'recruitment.create' => 'Create postings & applicants',
            'recruitment.update' => 'Edit postings & applicants',
            'recruitment.delete' => 'Delete postings & applicants',
            'recruitment.manage-pipeline' => 'Move applications & reject',
            'recruitment.schedule-interviews' => 'Schedule & record interviews',
            'recruitment.hire' => 'Hire applicants (create employees)',
            'recruitment.export' => 'Export recruitment data',
        ],
        'Onboarding' => [
            'onboarding.view' => 'View onboarding',
            'onboarding.manage' => 'Start onboarding & manage checklists',
            'onboarding.manage-programs' => 'Manage onboarding programs (templates)',
        ],
        'Leave Management' => [
            'leave.view' => 'View leave requests & balances',
            'leave.request' => 'File & cancel leave requests',
            'leave.manage' => 'Approve / reject leave & set balances',
        ],
        'Attendance' => [
            'attendance.view' => 'View attendance & time records',
            'attendance.manage' => 'Manual entry, corrections & approvals',
            'attendance.clock' => 'Clock in / out (self-service)',
        ],
        'Performance Management' => [
            'performance.view' => 'View performance evaluations',
            'performance.manage' => 'Open, score, submit & acknowledge evaluations',
        ],
        'Predictive Analytics' => [
            'analytics.attrition.view' => 'View attrition risk',
            'analytics.attrition.manage' => 'Run attrition-risk assessments',
            'analytics.promotion.view' => 'View promotion readiness',
            'analytics.promotion.manage' => 'Run promotion-readiness assessments',
            'analytics.performance.view' => 'View performance forecast',
            'analytics.performance.manage' => 'Run performance forecasts',
        ],
        'Training & Development' => [
            'training.view' => 'View training programs & enrollments',
            'training.manage' => 'Manage programs, enroll employees & grade',
        ],
        'Awards & Recognition' => [
            'awards.view' => 'View awards & recognition',
            'awards.manage' => 'Give, edit & remove recognitions',
        ],
        'Events & Meetings' => [
            'events.view' => 'View events & meetings',
            'events.manage' => 'Schedule events, invite attendees & track responses',
        ],
        'Offboarding' => [
            'offboarding.view' => 'View offboarding & clearance',
            'offboarding.manage' => 'Start exits, manage clearance & finalize',
            'offboarding.manage-programs' => 'Manage clearance templates (programs)',
        ],
        'Company Setup' => [
            'setup.company.view' => 'View company profile',
            'setup.company.manage' => 'Manage company profile',
            'setup.schedule.view' => 'View work schedules & holidays',
            'setup.schedule.manage' => 'Manage work schedules & holidays',
            'setup.departments.view' => 'View departments & positions',
            'setup.departments.manage' => 'Manage departments & positions',
            'setup.leave-types.view' => 'View leave types',
            'setup.leave-types.manage' => 'Manage leave types',
            'setup.kpi.view' => 'View KPI & evaluation criteria',
            'setup.kpi.manage' => 'Manage KPI & evaluation criteria',
            'setup.award-types.view' => 'View award types',
            'setup.award-types.manage' => 'Manage award types',
        ],
        'User Management' => [
            'users.view' => 'View users',
            'users.create' => 'Create users',
            'users.update' => 'Edit users',
            'users.delete' => 'Archive users',
            'users.restore' => 'Restore archived users',
            'users.force-delete' => 'Permanently delete users',
            'users.manage-status' => 'Activate / deactivate users',
            'users.reset-password' => 'Reset user passwords',
            'users.export' => 'Export users',
        ],
        'Roles & Permissions' => [
            'roles.view' => 'View roles',
            'roles.create' => 'Create roles',
            'roles.update' => 'Edit roles & permissions',
            'roles.delete' => 'Delete roles',
            'roles.assign' => 'Assign roles to users',
        ],
        'Activity Logs' => [
            'activity-logs.view' => 'View activity logs',
            'activity-logs.delete' => 'Delete & clear activity logs',
            'activity-logs.export' => 'Export activity logs',
        ],
        'Notifications' => [
            'notifications.send' => 'Send & broadcast notifications',
        ],
    ];

    /**
     * Every permission group with its name/label pairs, for the frontend.
     *
     * @return list<array{group: string, permissions: list<array{name: string, label: string}>}>
     */
    public static function groups(): array
    {
        return collect(self::GROUPS)
            ->map(fn (array $permissions, string $group): array => [
                'group' => $group,
                'permissions' => collect($permissions)
                    ->map(fn (string $label, string $name): array => [
                        'name' => $name,
                        'label' => $label,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * A flat list of every permission name.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return self::all()->keys()->all();
    }

    /**
     * Every permission as name => [label, group].
     *
     * @return Collection<string, array{label: string, group: string}>
     */
    public static function all(): Collection
    {
        return collect(self::GROUPS)->flatMap(
            fn (array $permissions, string $group): array => collect($permissions)
                ->map(fn (string $label): array => ['label' => $label, 'group' => $group])
                ->all()
        );
    }

    /**
     * Resolve the human group a permission belongs to.
     */
    public static function groupFor(string $permission): ?string
    {
        return self::all()->get($permission)['group'] ?? null;
    }
}
