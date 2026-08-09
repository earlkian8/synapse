<?php

namespace App\Services\Assistant\Modules;

use App\Http\Controllers\Leave\LeaveRequestController;
use App\Http\Requests\Leave\StoreLeaveRequestRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\HolidayCalendar;
use App\Support\LeaveCalculator;
use App\Support\Notifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Leave capability: find, file, review (approve/reject) and cancel leave
 * requests. Chargeable days are always computed server-side (never trusted from
 * the model), mirroring {@see LeaveRequestController}.
 */
class LeaveModule extends Module
{
    public function key(): string
    {
        return 'leave';
    }

    public function isAvailable(User $user): bool
    {
        return $user->can('leave.view');
    }

    protected function toolMap(): array
    {
        return [
            'find_leave_requests' => 'findRequests',
            'file_leave_request' => 'fileRequest',
            'review_leave_request' => 'reviewRequest',
            'cancel_leave_request' => 'cancelRequest',
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $types = LeaveType::where('is_active', true)->orderBy('name')->get(['name', 'code'])
            ->map(fn (LeaveType $t): string => "{$t->name} ({$t->code})")->implode(', ') ?: 'none';

        return <<<TXT
        LEAVE — time-off requests with an approval lifecycle (pending → approved/rejected, or cancelled).
        - file_leave_request files for an employee; working days are computed server-side and a half day must be a single day. A type that does not require approval is auto-approved.
        - review_leave_request and cancel_leave_request act on the employee's most recent matching request — confirm you have the right person.
        - Pass `employee` as a name or employee number and `leave_type` as a type name or code.
          Leave types: {$types}
        TXT;
    }

    public function tools(User $user): array
    {
        return [
            [
                'name' => 'find_leave_requests',
                'description' => 'List leave requests, optionally filtered by employee name and/or status.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'query' => ['type' => 'STRING', 'description' => 'Employee name or number to filter by.'],
                        'status' => ['type' => 'STRING', 'enum' => LeaveRequest::STATUSES],
                    ],
                ],
            ],
            [
                'name' => 'file_leave_request',
                'description' => 'File a leave request for an employee. Working days are computed automatically.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or employee number.'],
                        'leave_type' => ['type' => 'STRING', 'description' => 'Leave type name or code.'],
                        'start_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD'],
                        'end_date' => ['type' => 'STRING', 'description' => 'YYYY-MM-DD; defaults to start_date.'],
                        'is_half_day' => ['type' => 'BOOLEAN'],
                        'half_day_period' => ['type' => 'STRING', 'enum' => ['morning', 'afternoon']],
                        'reason' => ['type' => 'STRING'],
                    ],
                    'required' => ['employee', 'leave_type', 'start_date'],
                ],
            ],
            [
                'name' => 'review_leave_request',
                'description' => "Approve or reject an employee's pending leave request.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or employee number.'],
                        'action' => ['type' => 'STRING', 'enum' => ['approve', 'reject']],
                        'review_note' => ['type' => 'STRING'],
                    ],
                    'required' => ['employee', 'action'],
                ],
            ],
            [
                'name' => 'cancel_leave_request',
                'description' => "Cancel an employee's pending or approved leave request.",
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or employee number.'],
                    ],
                    'required' => ['employee'],
                ],
            ],
        ];
    }

    // ── Tools ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findRequests(User $user, array $args): ToolResult
    {
        $query = trim((string) $this->firstFilled($args, ['query', 'employee', 'match']));
        $status = $this->normaliseStatus($args['status'] ?? null);

        $requests = LeaveRequest::query()
            ->with(['employee.department', 'employee.position', 'type'])
            ->when($query !== '', fn ($q) => $q->whereHas('employee', fn ($e) => $this->matchByTokens($e, $query)))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('start_date')
            ->limit(8)
            ->get();

        $cards = $requests->map(fn (LeaveRequest $r): array => $this->requestCard($r, 'find', 'neutral', ucfirst($r->status)))->all();

        $label = $query !== '' ? "Searched leave for “{$query}”" : 'Listed leave requests';

        return ToolResult::found($label, count($cards).' found', $cards);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function fileRequest(User $user, array $args): ToolResult
    {
        if ($user->cannot('leave.request')) {
            return $this->denied('file leave requests');
        }

        $employee = $this->locateEmployee($args);
        if (! $employee) {
            return ToolResult::error('Looked up the employee', 'No matching employee found.');
        }

        $type = $this->locateType($args);
        if (! $type) {
            return ToolResult::error('Looked up the leave type', 'No active leave type matched.');
        }

        $data = [
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $this->date($args['start_date'] ?? null),
            'end_date' => $this->date($args['end_date'] ?? null) ?? $this->date($args['start_date'] ?? null),
            'is_half_day' => filter_var($args['is_half_day'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'half_day_period' => $args['half_day_period'] ?? null,
            'reason' => $args['reason'] ?? null,
        ];

        $validator = Validator::make($data, (new StoreLeaveRequestRequest)->rules());

        if ($validator->fails()) {
            return ToolResult::error('Validated the leave request', $validator->errors()->first());
        }

        $start = Carbon::parse($data['start_date']);
        $end = Carbon::parse($data['end_date']);
        $isHalfDay = $data['is_half_day'];

        if ($isHalfDay && ! $start->isSameDay($end)) {
            return ToolResult::error('Validated the leave request', 'A half day must start and end on the same date.');
        }

        if ($isHalfDay && ! $type->allow_half_day) {
            return ToolResult::error('Validated the leave request', "{$type->name} does not allow half-day leave.");
        }

        $holidays = HolidayCalendar::datesInRange($start, $end);
        $days = LeaveCalculator::chargeableDays($start, $end, $isHalfDay, $holidays);

        if ($days <= 0) {
            return ToolResult::error('Computed the leave duration', 'That date range has no working days.');
        }

        $autoApprove = ! $type->requires_approval;

        $leave = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'days' => $days,
            'is_half_day' => $isHalfDay,
            'half_day_period' => $isHalfDay ? ($data['half_day_period'] ?: null) : null,
            'reason' => $data['reason'],
            'status' => $autoApprove ? 'approved' : 'pending',
            'filed_by' => $user->id,
            'reviewed_by' => $autoApprove ? $user->id : null,
            'reviewed_at' => $autoApprove ? now() : null,
        ]);

        ActivityLogger::log(
            event: 'created',
            description: "Filed {$type->name} for {$employee->full_name} via assistant",
            subject: $leave,
            properties: ['days' => $days, 'auto_approved' => $autoApprove],
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
                actor: $user,
            );
        }

        $leave->setRelation('employee', $employee)->setRelation('type', $type);

        return ToolResult::ok(
            ($autoApprove ? 'Filed & approved ' : 'Filed ')."{$type->name} for {$employee->full_name}",
            $days.' day'.($days == 1.0 ? '' : 's'),
            $this->requestCard($leave, $autoApprove ? 'approve' : 'add', $autoApprove ? 'positive' : 'info', $autoApprove ? 'Approved' : 'Filed'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function reviewRequest(User $user, array $args): ToolResult
    {
        if ($user->cannot('leave.manage')) {
            return $this->denied('approve or reject leave');
        }

        $action = strtolower(trim((string) ($args['action'] ?? '')));
        if (! in_array($action, ['approve', 'reject'], true)) {
            return ToolResult::error('Reviewed the leave request', 'Action must be approve or reject.');
        }

        $leave = $this->locateRequest($args, ['pending']);
        if (! $leave) {
            return ToolResult::error('Looked up the leave request', 'No pending request found for that employee.');
        }

        $approved = $action === 'approve';

        $leave->update([
            'status' => $approved ? 'approved' : 'rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'review_note' => $args['review_note'] ?? null,
        ]);

        ActivityLogger::log(
            event: 'updated',
            description: ($approved ? 'Approved ' : 'Rejected ')."{$leave->type->name} for {$leave->employee->full_name} via assistant",
            subject: $leave,
            logName: 'leave',
            subjectLabel: $leave->employee->full_name,
        );

        $this->notifyOutcome($leave, $approved, $user);

        return ToolResult::ok(
            ($approved ? 'Approved ' : 'Rejected ')."{$leave->employee->full_name}'s {$leave->type->name}",
            null,
            $this->requestCard($leave, $approved ? 'approve' : 'reject', $approved ? 'positive' : 'danger', $approved ? 'Approved' : 'Rejected'),
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function cancelRequest(User $user, array $args): ToolResult
    {
        if ($user->cannot('leave.request')) {
            return $this->denied('cancel leave requests');
        }

        $leave = $this->locateRequest($args, ['pending', 'approved']);
        if (! $leave) {
            return ToolResult::error('Looked up the leave request', 'No pending or approved request found for that employee.');
        }

        $leave->update(['status' => 'cancelled']);

        ActivityLogger::log(
            event: 'updated',
            description: "Cancelled {$leave->type->name} for {$leave->employee->full_name} via assistant",
            subject: $leave,
            logName: 'leave',
            subjectLabel: $leave->employee->full_name,
        );

        return ToolResult::ok(
            "Cancelled {$leave->employee->full_name}'s {$leave->type->name}",
            null,
            $this->requestCard($leave, 'cancel', 'warning', 'Cancelled'),
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function locateEmployee(array $args): ?Employee
    {
        $needle = $this->firstFilled($args, ['employee', 'match', 'employee_name', 'name']);

        return $needle ? $this->matchByTokens(Employee::query(), $needle)->first() : null;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function locateType(array $args): ?LeaveType
    {
        $needle = $this->firstFilled($args, ['leave_type', 'type', 'leave_type_name']);

        return $needle
            ? LeaveType::query()->where('is_active', true)->search($needle)->first()
            : null;
    }

    /**
     * Find the employee's most recent request in one of the allowed statuses.
     *
     * @param  array<string, mixed>  $args
     * @param  list<string>  $statuses
     */
    private function locateRequest(array $args, array $statuses): ?LeaveRequest
    {
        $employee = $this->locateEmployee($args);

        if (! $employee) {
            return null;
        }

        return LeaveRequest::query()
            ->with(['employee', 'type'])
            ->where('employee_id', $employee->id)
            ->whereIn('status', $statuses)
            ->latest('start_date')
            ->first();
    }

    private function normaliseStatus(mixed $status): ?string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, LeaveRequest::STATUSES, true) ? $status : null;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function notifyOutcome(LeaveRequest $leave, bool $approved, User $actor): void
    {
        if (! $leave->employee->user_id) {
            return;
        }

        $employeeUser = User::find($leave->employee->user_id);

        if (! $employeeUser) {
            return;
        }

        Notifier::toUser(
            $employeeUser,
            $approved ? 'Leave approved' : 'Leave rejected',
            "Your {$leave->type->name} on {$leave->start_date->format('M j')} was ".($approved ? 'approved.' : 'rejected.'),
            url: '/leave',
            level: $approved ? 'success' : 'warning',
            category: 'leave',
            actor: $actor,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestCard(LeaveRequest $leave, string $kind, string $tone, string $badge): array
    {
        $employee = $leave->employee;
        $range = $leave->start_date->isSameDay($leave->end_date)
            ? $leave->start_date->format('M j')
            : $leave->start_date->format('M j').' – '.$leave->end_date->format('M j');

        return $this->card(
            kind: $kind,
            tone: $tone,
            badge: $badge,
            title: $employee?->full_name ?? 'Employee',
            subtitle: trim(($leave->type?->name ?? 'Leave').' · '.$range),
            meta: [(float) $leave->days.' day'.((float) $leave->days == 1.0 ? '' : 's'), $leave->status],
            avatar: $employee
                ? ['name' => $employee->full_name, 'initials' => $employee->initials(), 'photo' => $employee->photo_url]
                : null,
            id: $leave->id,
        );
    }
}
