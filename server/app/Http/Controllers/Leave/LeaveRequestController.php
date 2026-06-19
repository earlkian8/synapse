<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\StoreLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Queries\LeaveBalanceService;
use App\Queries\LeaveRequestsIndexQuery;
use App\Queries\LeaveStatistics;
use App\Support\ActivityLogger;
use App\Support\HolidayCalendar;
use App\Support\LeaveCalculator;
use App\Support\Notifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeaveRequestController extends Controller
{
    /**
     * The leave inbox — a status-filtered queue of requests.
     */
    public function index(Request $request, LeaveRequestsIndexQuery $query, LeaveStatistics $statistics): Response
    {
        return Inertia::render('leave/index', [
            'requests' => LeaveRequestResource::collection($query->get($request))->resolve($request),
            'stats' => $statistics->toArray(),
            'options' => $this->indexOptions(),
            'can' => $this->permissions($request),
            'filters' => [
                'search' => $request->string('search')->toString(),
                'status' => $query->status($request),
                'type' => $request->integer('type') ?: null,
                'department' => $request->integer('department') ?: null,
            ],
        ]);
    }

    /**
     * A single request with its balance snapshot — the review drawer fetch.
     */
    public function show(Request $request, LeaveRequest $leaveRequest, LeaveBalanceService $balances): LeaveRequestResource
    {
        $leaveRequest->load([
            'employee:id,first_name,middle_name,last_name,suffix,employee_no,photo,department_id,position_id',
            'employee.department:id,name',
            'employee.position:id,title',
            'type:id,name,code,color,is_paid',
            'filer:id,first_name,middle_name,last_name,suffix',
            'reviewer:id,first_name,middle_name,last_name,suffix',
        ]);

        if ($leaveRequest->type) {
            $leaveRequest->balanceSnapshot = $balances->snapshot(
                $leaveRequest->employee_id,
                $leaveRequest->type,
                (int) $leaveRequest->start_date->year,
            );
        }

        return new LeaveRequestResource($leaveRequest);
    }

    /**
     * File a leave request. Days are computed server-side; a type that does not
     * require approval is auto-approved on file.
     */
    public function store(StoreLeaveRequestRequest $request): RedirectResponse
    {
        $range = $request->dateRange();
        $type = LeaveType::findOrFail($request->integer('leave_type_id'));
        $holidays = HolidayCalendar::datesInRange($range['start'], $range['end']);
        $days = LeaveCalculator::chargeableDays($range['start'], $range['end'], $range['isHalfDay'], $holidays);

        if ($days <= 0) {
            return $this->respond('That date range has no working days.', 'warning');
        }

        $autoApprove = ! $type->requires_approval;

        $leave = LeaveRequest::create([
            'employee_id' => $request->integer('employee_id'),
            'leave_type_id' => $type->id,
            'start_date' => $range['start']->toDateString(),
            'end_date' => $range['end']->toDateString(),
            'days' => $days,
            'is_half_day' => $range['isHalfDay'],
            'half_day_period' => $range['period'],
            'reason' => $request->input('reason'),
            'status' => $autoApprove ? 'approved' : 'pending',
            'filed_by' => $request->user()->id,
            'reviewed_by' => $autoApprove ? $request->user()->id : null,
            'reviewed_at' => $autoApprove ? now() : null,
        ]);

        $leave->load('employee:id,first_name,middle_name,last_name,suffix');

        ActivityLogger::log(
            event: 'created',
            description: "Filed {$type->name} for {$leave->employee->full_name}",
            subject: $leave,
            properties: ['days' => $days, 'auto_approved' => $autoApprove],
            logName: 'leave',
            subjectLabel: $leave->employee->full_name,
        );

        if (! $autoApprove) {
            $this->notifyReviewers($leave, $type);
        }

        return $this->respond($autoApprove ? 'Leave filed and approved.' : 'Leave request filed.');
    }

    /**
     * Edit a still-pending request (dates, type, reason). Days are recomputed.
     */
    public function update(StoreLeaveRequestRequest $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        if (! $leaveRequest->isPending()) {
            return $this->respond('Only pending requests can be edited.', 'warning');
        }

        $range = $request->dateRange();
        $holidays = HolidayCalendar::datesInRange($range['start'], $range['end']);
        $days = LeaveCalculator::chargeableDays($range['start'], $range['end'], $range['isHalfDay'], $holidays);

        if ($days <= 0) {
            return $this->respond('That date range has no working days.', 'warning');
        }

        $leaveRequest->update([
            'employee_id' => $request->integer('employee_id'),
            'leave_type_id' => $request->integer('leave_type_id'),
            'start_date' => $range['start']->toDateString(),
            'end_date' => $range['end']->toDateString(),
            'days' => $days,
            'is_half_day' => $range['isHalfDay'],
            'half_day_period' => $range['period'],
            'reason' => $request->input('reason'),
        ]);

        return $this->respond('Leave request updated.');
    }

    /**
     * Approve or reject a pending request.
     */
    public function review(Request $request, LeaveRequest $leaveRequest): RedirectResponse
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['approve', 'reject'])],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);

        if (! $leaveRequest->isPending()) {
            return $this->respond('This request has already been reviewed.', 'warning');
        }

        $approved = $validated['action'] === 'approve';

        $leaveRequest->update([
            'status' => $approved ? 'approved' : 'rejected',
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_note' => $validated['review_note'] ?? null,
        ]);

        $leaveRequest->load('employee:id,user_id,first_name,middle_name,last_name,suffix', 'type:id,name');

        ActivityLogger::log(
            event: 'updated',
            description: ($approved ? 'Approved ' : 'Rejected ')."{$leaveRequest->type->name} for {$leaveRequest->employee->full_name}",
            subject: $leaveRequest,
            logName: 'leave',
            subjectLabel: $leaveRequest->employee->full_name,
        );

        $this->notifyOutcome($leaveRequest, $approved);

        return $this->respond($approved ? 'Leave approved.' : 'Leave rejected.');
    }

    /**
     * Cancel a pending or approved request.
     */
    public function cancel(LeaveRequest $leaveRequest): RedirectResponse
    {
        if (! in_array($leaveRequest->status, ['pending', 'approved'], true)) {
            return $this->respond('This request cannot be cancelled.', 'warning');
        }

        $leaveRequest->update(['status' => 'cancelled']);
        $leaveRequest->load('employee:id,first_name,middle_name,last_name,suffix', 'type:id,name');

        ActivityLogger::log(
            event: 'updated',
            description: "Cancelled {$leaveRequest->type->name} for {$leaveRequest->employee->full_name}",
            subject: $leaveRequest,
            logName: 'leave',
            subjectLabel: $leaveRequest->employee->full_name,
        );

        return $this->respond('Leave request cancelled.');
    }

    /**
     * Permanently remove a request.
     */
    public function destroy(LeaveRequest $leaveRequest): RedirectResponse
    {
        $leaveRequest->load('employee:id,first_name,middle_name,last_name,suffix');
        $name = $leaveRequest->employee?->full_name ?? 'employee';
        $leaveRequest->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: "Deleted a leave request for {$name}",
            logName: 'leave',
            subjectLabel: $name,
        );

        return $this->respond('Leave request deleted.');
    }

    /**
     * Permission flags shared with the front-end.
     *
     * @return array<string, bool>
     */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        return [
            'request' => $user->can('leave.request'),
            'manage' => $user->can('leave.manage'),
        ];
    }

    /**
     * Options for the inbox: type & department filters and employees to file for.
     *
     * @return array<string, mixed>
     */
    private function indexOptions(): array
    {
        return [
            'types' => LeaveType::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'color', 'is_paid', 'allow_half_day', 'requires_approval']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'employees' => Employee::query()
                ->orderBy('first_name')
                ->limit(500)
                ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no'])
                ->map(fn (Employee $e): array => [
                    'id' => $e->id,
                    'full_name' => $e->full_name,
                    'employee_no' => $e->employee_no,
                ]),
        ];
    }

    /**
     * Notify those who can approve leave that a new request is waiting.
     */
    private function notifyReviewers(LeaveRequest $leave, LeaveType $type): void
    {
        Notifier::toRole(
            'hr-manager',
            'Leave request to review',
            "{$leave->employee->full_name} filed {$type->name} ({$leave->days} day".($leave->days == 1.0 ? '' : 's').').',
            url: '/leave',
            category: 'leave',
            actor: request()->user(),
        );
    }

    /**
     * Tell the employee (and whoever filed it) the decision.
     */
    private function notifyOutcome(LeaveRequest $leave, bool $approved): void
    {
        $title = $approved ? 'Leave approved' : 'Leave rejected';
        $body = "Your {$leave->type->name} on {$leave->start_date->format('M j')} was ".($approved ? 'approved.' : 'rejected.');

        if ($leave->employee->user_id) {
            $employeeUser = User::find($leave->employee->user_id);

            if ($employeeUser) {
                Notifier::toUser(
                    $employeeUser,
                    $title,
                    $body,
                    url: '/leave',
                    level: $approved ? 'success' : 'warning',
                    category: 'leave',
                    actor: request()->user(),
                );
            }
        }
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
