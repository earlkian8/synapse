<?php

namespace App\Services\Assistant;

use App\Models\User;

/**
 * Who is offered the assistant at all — its button in the app shell's top bar.
 *
 * Anyone with a module it can read for them: the directory, leave, attendance,
 * onboarding, offboarding, recruitment, performance, training, awards, events,
 * the org structure, the company profile, schedules and holidays, attendance
 * policies, work locations, leave types, award types, the performance framework,
 * recruitment pipelines, clearance templates, the audit trail, accounts, roles,
 * or any of the predictive surfaces (reports follow the same module permissions).
 * Self-service alone does not open it: every turn spends model quota.
 *
 * Shared with the browser as `auth.assistant`, so the launcher and the Help
 * Center's articles about the assistant answer the same question the same way.
 * What a person can then do in the chat is still decided module by module.
 */
final class AssistantAccess
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'employees.view',
        'leave.view',
        'leave.manage',
        'attendance.view',
        'onboarding.view',
        'offboarding.view',
        'recruitment.view',
        'performance.view',
        'training.view',
        'awards.view',
        'events.view',
        'setup.departments.view',
        'setup.company.view',
        'setup.schedule.view',
        'setup.attendance-policies.view',
        'setup.locations.view',
        'setup.leave-types.view',
        'setup.award-types.view',
        'setup.kpi.view',
        'recruitment.configure-pipelines',
        'offboarding.manage-programs',
        'activity-logs.view',
        'users.view',
        'roles.view',
        'analytics.attrition.view',
        'analytics.promotion.view',
        'analytics.performance.view',
    ];

    /**
     * Whether the assistant is offered to this person in the workspace they
     * are in. Super admins pass through the gate's `before` hook.
     */
    public static function offeredTo(User $user): bool
    {
        return collect(self::PERMISSIONS)->contains(fn (string $permission): bool => $user->can($permission));
    }
}
