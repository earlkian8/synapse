<?php

namespace App\Services\Assistant\Modules;

use App\Http\Controllers\Leave\LeaveRequestController;
use App\Http\Requests\Leave\ReviewLeaveRequestRequest;
use App\Http\Requests\Leave\StoreLeaveBalanceRequest;
use App\Http\Requests\Leave\StoreLeaveRequestRequest;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Queries\LeaveBalanceService;
use App\Services\Assistant\Contracts\ContributesContext;
use App\Services\Assistant\Contracts\ExplainsConsequences;
use App\Services\Assistant\Retrieval\ContextSection;
use App\Services\Assistant\Retrieval\RetrievedSubject;
use App\Services\Assistant\ToolResult;
use App\Support\ActivityLogger;
use App\Support\HolidayCalendar;
use App\Support\Leave\LeaveAccess;
use App\Support\Leave\LeaveEntitlements;
use App\Support\Leave\LeaveReview;
use App\Support\LeaveCalculator;
use App\Support\Notifier;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Leave capability: find, file, review (approve/reject) and cancel leave
 * requests. Chargeable days are always computed server-side (never trusted from
 * the model), mirroring {@see LeaveRequestController}.
 */
class LeaveModule extends Module implements ContributesContext, ExplainsConsequences
{
    private const CHANNEL = ' via assistant';

    /** Words that mean the asker. */
    private const SELF = ['me', 'myself', 'my', 'mine', 'i', 'self'];

    public function key(): string
    {
        return 'leave';
    }

    /**
     * Anybody who may see leave, or file their own (self-service, ADR 0059).
     */
    public function isAvailable(User $user): bool
    {
        return $user->can('leave.view') || $user->can('leave.request');
    }

    /** How many recent requests a read-out lists before it stops. */
    private const CONTEXT_REQUESTS = 5;

    /**
     * What this person is entitled to, what they have spent, and what they have
     * asked for lately.
     *
     * Entitlements come from {@see LeaveBalanceService}, which is the only place
     * that knows a balance is an allocation minus derived usage rather than a
     * stored number — so a figure quoted in chat is the same figure the balances
     * screen shows, down to the rounding.
     */
    public function contextFor(User $user, RetrievedSubject $subject): ?ContextSection
    {
        $employee = $subject->employeeModel();

        if ($employee === null || $user->cannot('leave.view')) {
            return null;
        }

        $year = (int) Carbon::today()->year;

        $types = LeaveType::where('is_active', true)->orderBy('name')->get();
        $balances = $types->isEmpty()
            ? []
            : (app(LeaveBalanceService::class)->forEmployees(collect([$employee]), $types, $year)[$employee->id] ?? []);

        // Only the types that mean something for this person: an untouched
        // entitlement of zero is noise in a briefing.
        $used = array_values(array_filter(
            $balances,
            fn (array $row): bool => $row['used'] > 0 || $row['pending'] > 0 || $row['entitled'] > 0,
        ));

        $requests = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->with('type:id,name')
            ->orderByDesc('start_date')
            ->limit(self::CONTEXT_REQUESTS)
            ->get();

        if ($used === [] && $requests->isEmpty()) {
            return null;
        }

        $pending = $requests->where('status', 'pending')->count();

        return ContextSection::of('Leave ('.$year.')', [
            $used !== []
                ? 'Balances — '.implode('; ', array_map(
                    fn (array $row): string => $row['name'].': '.$row['remaining'].' of '.$row['entitled'].' days left'.
                        ($row['pending'] > 0 ? ', '.$row['pending'].' pending' : ''),
                    $used,
                ))
                : 'No entitlements are allocated for this year.',
            $requests->isNotEmpty()
                ? 'Recent requests — '.$requests->map(fn (LeaveRequest $r): string => sprintf(
                    '%s %s%s (%s day%s, %s)',
                    $r->type?->name ?? 'Leave',
                    $r->start_date?->toDateString() ?? '?',
                    $r->end_date && ! $r->end_date->isSameDay($r->start_date) ? ' to '.$r->end_date->toDateString() : '',
                    rtrim(rtrim((string) $r->days, '0'), '.'),
                    (float) $r->days === 1.0 ? '' : 's',
                    $r->status,
                ))->implode('; ')
                : 'No leave has been filed.',
            $pending > 0 ? $pending.' request'.($pending === 1 ? '' : 's').' waiting for a decision' : null,
        ]);
    }

    protected function confirmTools(): array
    {
        // Cancelling withdraws leave somebody is counting on.
        return [
            'cancel_leave_request',
            // Changes what somebody may take for a whole year.
            'set_leave_entitlement',
        ];
    }

    protected function permissionMap(): array
    {
        return [
            'file_leave_request' => 'leave.request',
            'cancel_leave_request' => 'leave.request',
            'review_leave_request' => 'leave.manage',
            'set_leave_entitlement' => 'leave.manage',
        ];
    }

    protected function toolMap(): array
    {
        return [
            'find_leave_requests' => 'findRequests',
            'file_leave_request' => 'fileRequest',
            'review_leave_request' => 'reviewRequest',
            'cancel_leave_request' => 'cancelRequest',
            'get_leave_balances' => 'balances',
            'set_leave_entitlement' => 'setEntitlement',
        ];
    }

    public function run(User $user, string $tool, array $args): ToolResult
    {
        if (! app(Tenancy::class)->check()) {
            return ToolResult::error('Checked the workspace', 'No workspace is selected.');
        }

        return $this->{$this->toolMap()[$tool]}($user, $args);
    }

    public function guidance(User $user): string
    {
        $types = $this->catalog(LeaveType::where('is_active', true)->orderBy('name')->get(['name', 'code'])
            ->map(fn (LeaveType $t): string => "{$t->name} ({$t->code})"));

        return <<<TXT
        LEAVE — time-off requests with an approval lifecycle (pending → approved/rejected, or cancelled).
        - file_leave_request files for an employee; working days are computed server-side and a half day must be a single day. A type that does not require approval is auto-approved.
        - review_leave_request and cancel_leave_request act on the employee's most recent matching request — confirm you have the right person.
        - Pass `employee` as a name or employee number, or "me" for the user themselves, and `leave_type` as a type name or code.
          Leave types: {$types}
        - get_leave_balances reads entitlement, used, pending and remaining days by type — the user's own, or (with leave access) anybody's. set_leave_entitlement sets a year's allocation for one type and waits for confirmation.
        - Without leave management rights a user files, cancels and sees only their own leave.
        TXT;
    }

    public function tools(User $user): array
    {
        return $this->permitted($user, [
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
            [
                'name' => 'get_leave_balances',
                'description' => 'Leave balances by type for a year: entitled, used, pending, remaining. Defaults to the user themselves and this year.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or number, or "me".'],
                        'year' => ['type' => 'INTEGER'],
                    ],
                ],
            ],
            [
                'name' => 'set_leave_entitlement',
                'description' => 'Set how many days of one leave type an employee is entitled to in a year.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'employee' => ['type' => 'STRING', 'description' => 'Employee name or employee number.'],
                        'leave_type' => ['type' => 'STRING', 'description' => 'Leave type name or code.'],
                        'days' => ['type' => 'NUMBER'],
                        'year' => ['type' => 'INTEGER'],
                    ],
                    'required' => ['employee', 'leave_type', 'days'],
                ],
            ],
        ]);
    }

    // ── Tools ────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $args
     */
    private function findRequests(User $user, array $args): ToolResult
    {
        $query = trim((string) $this->firstFilled($args, ['query', 'employee', 'match']));
        $status = $this->normaliseStatus($args['status'] ?? null);

        // Without leave.view, a user sees only their own requests.
        $own = null;

        if ($user->cannot('leave.view') || in_array(strtolower($query), self::SELF, true)) {
            $own = LeaveAccess::ownEmployeeId($user);

            if ($own === null) {
                return ToolResult::error('Looked up your leave', 'Your account is not linked to an employee record here.');
            }

            $query = '';
        }

        $requests = LeaveRequest::query()
            ->with(['employee.department', 'employee.position', 'type'])
            ->when($own !== null, fn ($q) => $q->where('employee_id', $own))
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

        [$employee, $error] = $this->subject($user, $args);
        if (! $employee) {
            return ToolResult::error('Looked up the employee', $error);
        }

        if (! LeaveAccess::mayActFor($user, $employee->id)) {
            return ToolResult::error('Filed the leave request', 'You can only file leave for yourself.');
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

        [$employee, $error] = $this->resolveEmployee((string) $this->firstFilled($args, ['employee', 'match', 'employee_name', 'name']));
        if (! $employee) {
            return ToolResult::error('Looked up the employee', $error);
        }

        $leave = $this->latestRequest($employee, ['pending']);
        if (! $leave) {
            return ToolResult::error('Looked up the leave request', 'No pending request found for that employee.');
        }

        $note = filled($args['review_note'] ?? null) ? trim((string) $args['review_note']) : null;
        $problem = $this->invalid(['action' => $action, 'review_note' => $note], ReviewLeaveRequestRequest::rulesFor());

        if ($problem !== null) {
            return ToolResult::error('Reviewed the leave request', $problem);
        }

        $approved = $action === 'approve';

        if (! app(LeaveReview::class)->decide($leave, $approved, $note, $user, self::CHANNEL)) {
            return ToolResult::error('Reviewed the leave request', 'That request has already been reviewed.');
        }

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

        [$employee, $error] = $this->subject($user, $args);
        if (! $employee) {
            return ToolResult::error('Looked up the employee', $error);
        }

        if (! LeaveAccess::mayActFor($user, $employee->id)) {
            return ToolResult::error('Cancelled the leave request', 'You can only cancel your own leave.');
        }

        $leave = $this->latestRequest($employee, ['pending', 'approved']);
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

    /**
     * @param  array<string, mixed>  $args
     */
    private function balances(User $user, array $args): ToolResult
    {
        [$employee, $error] = $this->subject($user, [...$args, 'employee' => $args['employee'] ?? 'me']);

        if (! $employee) {
            return ToolResult::error('Looked up the employee', $error);
        }

        if ($employee->id !== LeaveAccess::ownEmployeeId($user) && $user->cannot('leave.view')) {
            return $this->denied("see other people's leave balances");
        }

        $year = is_int($args['year'] ?? null) && $args['year'] >= 2000 && $args['year'] <= 2100 ? $args['year'] : (int) Carbon::today()->year;
        $types = LeaveType::where('is_active', true)->orderBy('name')->get();
        $rows = $types->isEmpty() ? [] : (app(LeaveBalanceService::class)->forEmployees(collect([$employee]), $types, $year)[$employee->id] ?? []);

        $card = $this->card(
            kind: 'insight',
            tone: 'info',
            badge: (string) $year,
            title: "{$employee->full_name}'s leave",
            subtitle: $employee->employee_no,
            meta: $rows === [] ? ['No active leave types'] : array_map(
                fn (array $r): string => "{$r['name']}: {$this->days($r['remaining'])} left of {$this->days($r['entitled'])}"
                    .($r['used'] > 0 ? ", {$this->days($r['used'])} used" : '')
                    .($r['pending'] > 0 ? ", {$this->days($r['pending'])} pending" : ''),
                $rows,
            ),
            id: $employee->id,
        );

        return ToolResult::found("Read {$employee->full_name}'s leave balances", null, [$card]);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function setEntitlement(User $user, array $args): ToolResult
    {
        if ($user->cannot('leave.manage')) {
            return $this->denied('set leave entitlements');
        }

        [$employee, $error] = $this->resolveEmployee((string) ($args['employee'] ?? ''));

        if (! $employee) {
            return ToolResult::error('Looked up the employee', $error);
        }

        $type = $this->locateType($args);

        if (! $type) {
            return ToolResult::error('Looked up the leave type', 'No active leave type matched.');
        }

        $year = is_int($args['year'] ?? null) ? $args['year'] : (int) Carbon::today()->year;
        $data = ['employee_id' => $employee->id, 'year' => $year, 'balances' => [['leave_type_id' => $type->id, 'entitled_days' => $args['days'] ?? null]]];
        $problem = $this->invalid($data, (new StoreLeaveBalanceRequest)->rules(), [], ['balances.0.entitled_days' => 'days']);

        if ($problem !== null) {
            return ToolResult::error('Set the entitlement', $problem);
        }

        app(LeaveEntitlements::class)->set($employee, $year, $data['balances'], self::CHANNEL);

        $row = app(LeaveBalanceService::class)->snapshot($employee->id, $type, $year);

        return ToolResult::ok(
            "Set {$employee->full_name}'s {$type->name} to {$this->days((float) $args['days'])} for {$year}",
            "{$this->days($row['remaining'])} left after {$this->days($row['used'])} used.",
        );
    }

    public function consequence(User $user, string $tool, array $args): ?string
    {
        if ($tool !== 'set_leave_entitlement') {
            return null;
        }

        [$employee] = $this->resolveEmployee((string) ($args['employee'] ?? ''));
        $type = $this->locateType($args);

        if ($employee === null || $type === null || ! is_numeric($args['days'] ?? null)) {
            return null;
        }

        $year = is_int($args['year'] ?? null) ? $args['year'] : (int) Carbon::today()->year;
        $row = app(LeaveBalanceService::class)->snapshot($employee->id, $type, $year);

        return "{$employee->full_name}'s {$type->name} for {$year} would go from {$this->days($row['entitled'])} to {$this->days((float) $args['days'])} days; with {$this->days($row['used'])} used, "
            .$this->days((float) $args['days'] - $row['used']).' would be left.';
    }

    private function days(float|int $days): string
    {
        return rtrim(rtrim(number_format((float) $days, 1, '.', ''), '0'), '.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The employee a call is about: the user themselves for "me" (or when
     * nobody is named and they may only act for themselves), else exactly one
     * employee by name or number — never "the first Maria".
     *
     * @param  array<string, mixed>  $args
     * @return array{0: Employee|null, 1: string}
     */
    private function subject(User $user, array $args): array
    {
        $needle = trim((string) $this->firstFilled($args, ['employee', 'match', 'employee_name', 'name']));

        if ($needle === '' || in_array(strtolower($needle), self::SELF, true)) {
            $own = LeaveAccess::ownEmployeeId($user);

            return $own === null
                ? [null, $needle === '' ? 'Say whose leave.' : 'Your account is not linked to an employee record here.']
                : [Employee::query()->find($own), ''];
        }

        return $this->resolveEmployee($needle);
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
     * The employee's most recent request in one of the allowed statuses.
     *
     * @param  list<string>  $statuses
     */
    private function latestRequest(Employee $employee, array $statuses): ?LeaveRequest
    {
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
