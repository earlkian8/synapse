<?php

namespace App\Support\Attendance;

use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Decides attendance requests (ADR 0039) — the one place a decision is applied,
 * whether it came from the inbox, a bulk action, or the assistant.
 *
 * What approval does, by type, all inside one transaction:
 *
 *  - `correction` — opens the day if it has no record yet, then writes the
 *    proposed punches through {@see AttendanceClock::applyCorrection()}: the
 *    replaced punches are soft-deleted and name the request, the new ones are
 *    `source = correction`, and the day is marked manual and recomputed.
 *  - `overtime` — recomputes the day, whose evaluator now finds the grant:
 *    approved overtime becomes `min(requested, computed)` and the
 *    `unapproved_overtime` flag clears. A pre-approval for a day not yet worked
 *    has no day to recompute; the day finds it when it opens.
 *  - `official_business` — opens and recomputes every working day in range: a
 *    day with no punches becomes present for the shift's hours, flagged
 *    `official_business`.
 *  - `remote_work` — recomputes the days in range that exist, which gain the
 *    `remote_work` flag. Punches are still required; Phase 4's geofence reads
 *    the flag.
 *
 * Rejection recomputes the days too: a rejected overtime request is a decision,
 * so the day stops waiting for one.
 *
 * Refused: a request already decided, a request about the reviewer themselves,
 * and any decision touching a locked period — through the engine's own guard.
 */
class AttendanceRequestApprover
{
    public function __construct(
        private readonly AttendanceClock $clock,
        private readonly PeriodLock $lock,
    ) {}

    /**
     * @throws AttendanceException
     */
    public function approve(AttendanceRequest $request, User $reviewer, ?string $note = null): AttendanceRequest
    {
        return $this->decide($request, $reviewer, 'approved', $note);
    }

    /**
     * @throws AttendanceException
     */
    public function reject(AttendanceRequest $request, User $reviewer, ?string $note = null): AttendanceRequest
    {
        return $this->decide($request, $reviewer, 'rejected', $note);
    }

    /**
     * Withdraw a pending request. Only the employee it is about, whoever filed
     * it, or somebody who manages attendance may.
     *
     * @throws AttendanceException
     */
    public function cancel(AttendanceRequest $request, User $by): AttendanceRequest
    {
        $request->loadMissing('employee');

        $mayCancel = $request->employee?->user_id === $by->id
            || $request->requested_by === $by->id
            || $by->can('attendance.manage');

        if (! $mayCancel) {
            throw new AttendanceRequestException('You can only cancel your own requests.');
        }

        if (! $request->isPending()) {
            throw new AttendanceRequestException('Only a pending request can be cancelled.');
        }

        $request->update(['status' => 'cancelled']);

        ActivityLogger::log(
            event: 'updated',
            description: 'Cancelled '.AttendanceRequestFiler::label($request)." for {$request->employee?->full_name}",
            subject: $request,
            logName: 'attendance',
            subjectLabel: $request->employee?->full_name ?? 'employee',
        );

        return $request;
    }

    /**
     * Whether this user could decide this request at all — the check the UI and
     * the bulk action ask before offering or attempting it.
     */
    public function canDecide(AttendanceRequest $request, User $reviewer): bool
    {
        $request->loadMissing('employee:id,user_id');

        return $reviewer->can('attendance.requests.review')
            && $request->isPending()
            && ! $this->isOwn($request, $reviewer);
    }

    /**
     * @throws AttendanceException
     */
    private function decide(AttendanceRequest $request, User $reviewer, string $status, ?string $note): AttendanceRequest
    {
        $request = DB::transaction(function () use ($request, $reviewer, $status, $note): AttendanceRequest {
            // Two reviewers deciding the same request at once: the second waits,
            // then finds it already decided.
            $request = AttendanceRequest::query()->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
            $request->load('employee');

            if (! $request->isPending()) {
                throw new AttendanceRequestException('This request has already been '.$request->status.'.');
            }

            if ($this->isOwn($request, $reviewer)) {
                throw new AttendanceRequestException("You can't review your own request.");
            }

            $this->lock->assertRangeOpen($request->start_date->toDateString(), $request->end_date->toDateString());

            $request->update([
                'status' => $status,
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => now(),
                'review_note' => filled($note) ? trim((string) $note) : null,
            ]);

            if ($status === 'approved') {
                $this->apply($request, $reviewer);
            } else {
                $this->refreshExisting($request);
            }

            return $request;
        });

        $approved = $status === 'approved';

        ActivityLogger::log(
            event: 'updated',
            description: ($approved ? 'Approved ' : 'Rejected ').AttendanceRequestFiler::label($request)." for {$request->employee?->full_name}",
            subject: $request,
            properties: ['type' => $request->type, 'note' => $request->review_note],
            logName: 'attendance',
            subjectLabel: $request->employee?->full_name ?? 'employee',
        );

        $this->notifyOutcome($request, $reviewer, $approved);

        return $request;
    }

    /**
     * Make an approved request true of the days it covers.
     */
    private function apply(AttendanceRequest $request, User $reviewer): void
    {
        $employee = $request->employee;

        if ($employee === null) {
            return;
        }

        if ($request->type === 'correction') {
            $record = $this->clock->openRecord($employee, $request->start_date->toDateString());
            $this->clock->applyCorrection($record, $request->payload ?? [], $request, $reviewer->id);
            $request->update(['attendance_record_id' => $record->id]);

            return;
        }

        if ($request->type === 'official_business') {
            $this->openWorkingDays($request);

            return;
        }

        $this->refreshExisting($request);
    }

    /**
     * Official business makes a day present even when nobody punched, so every
     * working day it covers needs a record to say so.
     */
    private function openWorkingDays(AttendanceRequest $request): void
    {
        $employee = $request->employee;

        foreach ($request->dates() as $date) {
            $record = AttendanceRecord::query()->where('employee_id', $employee->id)->whereDate('work_date', $date)->first();

            if ($record === null && ! $this->clock->shiftFor($employee, $date)->isWorkingDay) {
                continue;
            }

            $this->clock->refresh($record ?? $this->clock->openRecord($employee, $date));
        }
    }

    /**
     * Recompute the days in range that already exist, so they read the decision.
     */
    private function refreshExisting(AttendanceRequest $request): void
    {
        AttendanceRecord::query()
            ->where('employee_id', $request->employee_id)
            ->whereBetween('work_date', [$request->start_date->toDateString(), $request->end_date->toDateString()])
            ->orderBy('work_date')
            ->get()
            ->each(fn (AttendanceRecord $record) => $this->clock->refresh($record));
    }

    private function isOwn(AttendanceRequest $request, User $reviewer): bool
    {
        return $request->employee?->user_id !== null && $request->employee->user_id === $reviewer->id;
    }

    /**
     * Tell the employee — and whoever filed it for them — what was decided, with
     * the reviewer's note.
     */
    private function notifyOutcome(AttendanceRequest $request, User $reviewer, bool $approved): void
    {
        $recipients = collect([$request->employee?->user_id, $request->requested_by])
            ->filter()
            ->unique()
            ->reject(fn (int $id): bool => $id === $reviewer->id);

        $body = 'Your request for '.AttendanceRequestFiler::label($request).' was '.($approved ? 'approved.' : 'rejected.')
            .($request->review_note ? " “{$request->review_note}”" : '');

        foreach (User::query()->whereIn('id', $recipients)->get() as $user) {
            Notifier::toUser(
                $user,
                $approved ? 'Attendance request approved' : 'Attendance request rejected',
                $body,
                url: '/attendance/me',
                level: $approved ? 'success' : 'warning',
                category: 'attendance',
                actor: $reviewer,
            );
        }
    }
}
