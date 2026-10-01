<?php

namespace App\Support\Leave;

use App\Http\Requests\Leave\ReviewLeaveRequestRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;

/**
 * Approving or rejecting a pending leave request (ADR 0059).
 *
 * The Leave inbox and the assistant both come through here, so a decision is
 * stored, recorded and told to the employee the same way whoever made it.
 * Validation is {@see ReviewLeaveRequestRequest}, which runs first.
 *
 * `$channel` is appended to the audit description (" via assistant").
 */
class LeaveReview
{
    /**
     * Decide a request. False when it is no longer pending (somebody else
     * reviewed or cancelled it first).
     */
    public function decide(LeaveRequest $leave, bool $approved, ?string $note, User $by, string $channel = ''): bool
    {
        if (! $leave->isPending()) {
            return false;
        }

        $leave->update([
            'status' => $approved ? 'approved' : 'rejected',
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ]);

        $leave->loadMissing(['employee', 'type']);

        ActivityLogger::log(
            event: 'updated',
            description: ($approved ? 'Approved ' : 'Rejected ')."{$leave->type->name} for {$leave->employee->full_name}{$channel}",
            subject: $leave,
            logName: 'leave',
            subjectLabel: $leave->employee->full_name,
        );

        $this->tellEmployee($leave, $approved, $by);

        return true;
    }

    /**
     * Tell the employee the decision, when they can sign in.
     */
    private function tellEmployee(LeaveRequest $leave, bool $approved, User $by): void
    {
        $employeeUser = $leave->employee->user_id ? User::find($leave->employee->user_id) : null;

        if ($employeeUser === null) {
            return;
        }

        Notifier::toUser(
            $employeeUser,
            $approved ? 'Leave approved' : 'Leave rejected',
            "Your {$leave->type->name} on {$leave->start_date->format('M j')} was ".($approved ? 'approved.' : 'rejected.'),
            url: '/leave',
            level: $approved ? 'success' : 'warning',
            category: 'leave',
            actor: $by,
        );
    }
}
