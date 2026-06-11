<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Requests\Leave\StoreLeaveBalanceRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Queries\LeaveBalanceService;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LeaveBalanceController extends Controller
{
    /**
     * Leave balances for a year — each employee's entitlement, used and remaining
     * days per type.
     */
    public function index(Request $request, LeaveBalanceService $service): Response
    {
        $year = $this->year($request);
        $search = $request->string('search')->toString();
        $department = $request->integer('department');

        $types = LeaveType::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'color', 'default_days']);

        $employees = Employee::query()
            ->when($search !== '', fn ($query) => $query->search($search))
            ->when($department > 0, fn ($query) => $query->where('department_id', $department))
            ->with('department:id,name')
            ->orderBy('first_name')
            ->limit(200)
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'suffix', 'employee_no', 'photo', 'department_id']);

        $balances = $service->forEmployees($employees, $types, $year);

        return Inertia::render('leave/balances', [
            'types' => $types->map(fn (LeaveType $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'code' => $t->code,
                'color' => $t->color,
                'default_days' => (float) $t->default_days,
            ]),
            'employees' => $employees->map(fn (Employee $e): array => [
                'id' => $e->id,
                'full_name' => $e->full_name,
                'initials' => $e->initials(),
                'employee_no' => $e->employee_no,
                'department' => $e->department?->name,
                'balances' => $balances[$e->id] ?? [],
            ]),
            'year' => $year,
            'years' => $this->yearOptions(),
            'options' => ['departments' => Department::orderBy('name')->get(['id', 'name'])],
            'can' => ['manage' => $request->user()->can('leave.manage')],
            'filters' => [
                'search' => $search,
                'department' => $department ?: null,
            ],
        ]);
    }

    /**
     * Set an employee's entitlements for a year (upserts the allocation rows).
     */
    public function store(StoreLeaveBalanceRequest $request): RedirectResponse
    {
        $employee = Employee::findOrFail($request->integer('employee_id'));
        $year = $request->integer('year');

        foreach ($request->input('balances') as $row) {
            LeaveBalance::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'leave_type_id' => $row['leave_type_id'],
                    'year' => $year,
                ],
                ['entitled_days' => $row['entitled_days']],
            );
        }

        ActivityLogger::log(
            event: 'updated',
            description: "Set {$year} leave entitlements for {$employee->full_name}",
            subject: $employee,
            logName: 'leave',
            subjectLabel: $employee->full_name,
        );

        return $this->respond('Entitlements saved.');
    }

    /**
     * The selected year, defaulting to the current one.
     */
    private function year(Request $request): int
    {
        $year = $request->integer('year');

        return $year >= 2000 && $year <= 2100 ? $year : (int) now()->year;
    }

    /**
     * Years offered in the selector: this year ± a small window.
     *
     * @return list<int>
     */
    private function yearOptions(): array
    {
        $current = (int) now()->year;

        return range($current + 1, $current - 3);
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
