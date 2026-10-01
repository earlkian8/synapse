<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreLeaveRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Http\Resources\LeaveTypeResource;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Queries\LeaveBalanceService;
use App\Support\ActivityLogger;
use App\Support\HolidayCalendar;
use App\Support\LeaveCalculator;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;

/**
 * Self-service leave for the mobile app. Every action is scoped to the signed-in
 * account's own employee record, and the canonical {@see LeaveCalculator},
 * {@see HolidayCalendar} and {@see LeaveBalanceService} are reused so the figures
 * match the web exactly.
 */
class LeaveController extends Controller
{
    /**
     * Active leave types available to file against.
     */
    public function types(Request $request): AnonymousResourceCollection
    {
        $this->employee($request);

        $types = LeaveType::where('is_active', true)->orderBy('name')->get();

        return LeaveTypeResource::collection($types);
    }

    /**
     * The employee's per-type balance for the given (or current) year.
     */
    public function balances(Request $request): JsonResponse
    {
        $employee = $this->employee($request);
        $year = $request->integer('year') ?: (int) now()->year;

        $types = LeaveType::where('is_active', true)->orderBy('name')->get();
        $balances = app(LeaveBalanceService::class)
            ->forEmployees(new Collection([$employee]), $types, $year);

        return response()->json([
            'data' => $balances[$employee->id] ?? [],
            'year' => $year,
        ]);
    }

    /**
     * The employee's own leave requests (most recent first, paginated).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $employee = $this->employee($request);

        $requests = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->with('type:id,name,code,color,is_paid')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return LeaveRequestResource::collection($requests);
    }

    /**
     * File a leave request for the signed-in employee. Days are computed
     * server-side; a type that does not require approval is auto-approved.
     */
    public function store(StoreLeaveRequest $request): JsonResponse
    {
        $employee = $this->employee($request);

        $range = $request->dateRange();
        $type = LeaveType::findOrFail($request->integer('leave_type_id'));
        $holidays = HolidayCalendar::datesInRange($range['start'], $range['end']);
        $days = LeaveCalculator::chargeableDays($range['start'], $range['end'], $range['isHalfDay'], $holidays);

        if ($days <= 0) {
            return response()->json(['message' => 'That date range has no working days.'], 422);
        }

        $autoApprove = ! $type->requires_approval;

        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
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

        ActivityLogger::log(
            event: 'created',
            description: "Filed {$type->name} for {$employee->full_name}",
            subject: $leave,
            properties: ['days' => $days, 'auto_approved' => $autoApprove, 'source' => 'mobile'],
            logName: 'leave',
            subjectLabel: $employee->full_name,
        );

        if (! $autoApprove) {
            Notifier::toRole(
                'hr-manager',
                'Leave request to review',
                "{$employee->full_name} filed {$type->name} ({$days} day".($days == 1.0 ? '' : 's').').',
                url: '/leave',
                category: 'leave',
                actor: $request->user(),
            );
        }

        $leave->load('type:id,name,code,color,is_paid');

        return response()->json([
            'data' => (new LeaveRequestResource($leave))->resolve($request),
            'message' => $autoApprove ? 'Leave filed and approved.' : 'Leave request filed.',
        ], 201);
    }

    /**
     * Cancel one of the employee's own pending or approved requests.
     */
    public function cancel(Request $request, int $leaveRequest): JsonResponse
    {
        $employee = $this->employee($request);

        $leave = LeaveRequest::query()
            ->where('id', $leaveRequest)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        if (! in_array($leave->status, ['pending', 'approved'], true)) {
            return response()->json(['message' => 'This request cannot be cancelled.'], 422);
        }

        $leave->update(['status' => 'cancelled']);
        $leave->load('type:id,name,code,color,is_paid');

        ActivityLogger::log(
            event: 'updated',
            description: "Cancelled {$leave->type->name} for {$employee->full_name}",
            subject: $leave,
            logName: 'leave',
            subjectLabel: $employee->full_name,
        );

        return response()->json([
            'data' => (new LeaveRequestResource($leave))->resolve($request),
            'message' => 'Leave request cancelled.',
        ]);
    }

    /**
     * Resolve the token user's Employee, or 403 if the account is unlinked.
     */
    private function employee(Request $request): Employee
    {
        $employee = $request->user()->employee()->first();

        abort_unless($employee !== null, 403, 'Your account is not linked to an employee record.');

        return $employee;
    }
}
