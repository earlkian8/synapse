<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeAwardResource;
use App\Models\Employee;
use App\Models\EmployeeAward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The signed-in employee's own recognitions for the mobile app. Self-scoped and
 * read-only — awards are granted from the web Awards module.
 */
class AwardController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $employee = $this->employee($request);

        $awards = EmployeeAward::query()
            ->where('employee_id', $employee->id)
            ->with(['awardType', 'grantedBy:id,first_name,last_name'])
            ->latestFirst()
            ->get();

        return EmployeeAwardResource::collection($awards);
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
