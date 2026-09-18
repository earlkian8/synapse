<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\BulkReviewAttendanceRequestsRequest;
use App\Http\Requests\Attendance\ReviewAttendanceRequestRequest;
use App\Http\Requests\Attendance\StoreAttendanceRequestRequest;
use App\Http\Resources\AttendanceRequestResource;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Support\Attendance\AttendanceException;
use App\Support\Attendance\AttendanceRequestApprover;
use App\Support\Attendance\AttendanceRequestFiler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Attendance requests on the web (ADR 0039): filing one (your own from
 * `/attendance/me`, or HR's on somebody's behalf), the review modal's fetch,
 * deciding one or many, and withdrawing a pending one.
 *
 * Thin by design: {@see AttendanceRequestFiler} and
 * {@see AttendanceRequestApprover} hold every rule, so the mobile API and the
 * assistant decide exactly as this does. The inbox itself is a tab of the
 * attendance board ({@see AttendanceController::index()}).
 */
class AttendanceRequestController extends Controller
{
    public function __construct(
        private readonly AttendanceRequestFiler $filer,
        private readonly AttendanceRequestApprover $approver,
    ) {}

    /**
     * File a request — for yourself, or (with `attendance.manage`) for somebody else.
     */
    public function store(StoreAttendanceRequestRequest $request): RedirectResponse
    {
        $employee = $request->filled('employee_id')
            ? Employee::findOrFail($request->integer('employee_id'))
            : $request->user()->employee;

        if ($employee === null) {
            return $this->respond('Your account is not linked to an employee record.', 'warning');
        }

        try {
            $this->filer->file($employee, $request->validated(), $request->user(), $request->file('attachment'));
        } catch (AttendanceException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Request sent for review.');
    }

    /**
     * One request with the day it concerns — the review modal's fetch. Visible to
     * the employee it is about, whoever filed it, and reviewers.
     */
    public function show(Request $request, AttendanceRequest $attendanceRequest): AttendanceRequestResource
    {
        $attendanceRequest->load([
            'employee:id,user_id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
            'employee.department:id,name',
            'employee.position:id,title',
            'reviewer:id,first_name,middle_name,last_name,suffix',
            'requester:id,first_name,middle_name,last_name,suffix',
            'record.punches',
            'replacedPunches',
        ]);

        $user = $request->user();

        abort_unless(
            $attendanceRequest->employee?->user_id === $user->id
                || $attendanceRequest->requested_by === $user->id
                || $user->can('attendance.requests.review')
                || $user->can('attendance.manage'),
            403,
        );

        // A single-day request filed before its day existed has no record link;
        // the day may exist now.
        if ($attendanceRequest->record === null && $attendanceRequest->start_date->equalTo($attendanceRequest->end_date)) {
            $attendanceRequest->setRelation('record', $attendanceRequest->employee
                ?->attendanceRecords()
                ->with('punches')
                ->whereDate('work_date', $attendanceRequest->start_date->toDateString())
                ->first());
        }

        return new AttendanceRequestResource($attendanceRequest);
    }

    /**
     * Approve or reject one request, with an optional note the employee sees.
     */
    public function review(ReviewAttendanceRequestRequest $request, AttendanceRequest $attendanceRequest): RedirectResponse
    {
        $approve = $request->string('action')->toString() === 'approve';

        try {
            $approve
                ? $this->approver->approve($attendanceRequest, $request->user(), $request->input('review_note'))
                : $this->approver->reject($attendanceRequest, $request->user(), $request->input('review_note'));
        } catch (AttendanceException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond($approve ? 'Request approved.' : 'Request rejected.');
    }

    /**
     * Decide many at once with one note. Each is decided on its own — one that
     * is the reviewer's own, already decided or locked is left, and counted.
     */
    public function bulkReview(BulkReviewAttendanceRequestsRequest $request): RedirectResponse
    {
        $approve = $request->string('action')->toString() === 'approve';
        $done = 0;
        $skipped = 0;

        $requests = AttendanceRequest::query()
            ->whereIn('id', $request->ids())
            ->orderBy('start_date')
            ->get();

        foreach ($requests as $attendanceRequest) {
            try {
                $approve
                    ? $this->approver->approve($attendanceRequest, $request->user(), $request->input('review_note'))
                    : $this->approver->reject($attendanceRequest, $request->user(), $request->input('review_note'));
                $done++;
            } catch (AttendanceException) {
                $skipped++;
            }
        }

        $verb = $approve ? 'Approved' : 'Rejected';

        return $this->respond(
            $done > 0
                ? "{$verb} {$done} ".str('request')->plural($done).'.'.($skipped > 0 ? " {$skipped} could not be — your own, already decided, or in a locked period." : '')
                : 'None of those could be decided — your own, already decided, or in a locked period.',
            $done > 0 ? 'success' : 'warning',
        );
    }

    /**
     * Withdraw a pending request.
     */
    public function cancel(Request $request, AttendanceRequest $attendanceRequest): RedirectResponse
    {
        try {
            $this->approver->cancel($attendanceRequest, $request->user());
        } catch (AttendanceException $e) {
            return $this->respond($e->getMessage(), 'warning');
        }

        return $this->respond('Request cancelled.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
