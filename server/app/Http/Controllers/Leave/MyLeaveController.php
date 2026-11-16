<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Http\Resources\LeaveRequestResource;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Queries\LeaveBalanceService;
use App\Support\Leave\LeaveAccess;
use App\Support\OrganizationClock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My leave on the web, `leave.request`: one's own balances and requests, and
 * filing and cancelling them — what the Staff role always held the permission
 * for, but had no screen to use on the web. Filing and cancelling go to the
 * same endpoints HR uses, which keep a self-service caller to their own record
 * ({@see LeaveAccess}); the figures come from the same
 * {@see LeaveBalanceService} as the app's.
 */
class MyLeaveController extends Controller
{
    public function __invoke(Request $request, LeaveBalanceService $balances): Response
    {
        $employee = $request->user()->employee()->first();
        $year = CarbonImmutable::parse(OrganizationClock::today())->year;
        $types = LeaveType::where('is_active', true)->orderBy('name')->get();

        $requests = $employee !== null
            ? LeaveRequest::query()
                ->where('employee_id', $employee->id)
                ->with(['type:id,name,code,color,is_paid', 'reviewer:id,first_name,middle_name,last_name,suffix'])
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->limit(100)
                ->get()
            : collect();

        return Inertia::render('leave/me', [
            'employee' => $employee !== null ? ['id' => $employee->id, 'full_name' => $employee->full_name, 'employee_no' => $employee->employee_no] : null,
            'year' => $year,
            'balances' => $employee !== null ? ($balances->forEmployees(new Collection([$employee]), $types, $year)[$employee->id] ?? []) : [],
            'requests' => LeaveRequestResource::collection($requests)->resolve($request),
            'types' => $types->map->only(['id', 'name', 'code', 'color', 'is_paid', 'allow_half_day', 'requires_approval'])->values(),
        ]);
    }
}
