<?php

namespace App\Support\Leave;

use App\Models\User;

/**
 * Whose leave somebody may file, edit and cancel (ADR 0059).
 *
 * `leave.request` is self-service: the Staff role's own description is "file
 * and cancel their own leave". Filing, editing or cancelling anybody else's
 * takes `leave.manage`. The web routes gated only on `leave.request` accepted
 * any employee — a staff member could file (and, for a type without approval,
 * auto-approve) leave for a colleague, or cancel theirs — while the phone app
 * was already confined to the caller's own record. The screen, the web request
 * and the assistant now all ask here.
 */
final class LeaveAccess
{
    /**
     * The employee this user is in the current workspace, or null when their
     * account is not linked to one.
     */
    public static function ownEmployeeId(User $user): ?int
    {
        $id = $user->employee()->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Whether this user may file, edit or cancel this employee's leave.
     */
    public static function mayActFor(User $user, int $employeeId): bool
    {
        return $user->can('leave.manage') || self::ownEmployeeId($user) === $employeeId;
    }
}
