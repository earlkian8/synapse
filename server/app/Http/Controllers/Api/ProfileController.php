<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use Illuminate\Http\Request;

/**
 * The signed-in employee's own 201 profile for the mobile app. Self-scoped: an
 * account resolves exactly one employee record (or 403 when unlinked).
 */
class ProfileController extends Controller
{
    public function show(Request $request): EmployeeProfileResource
    {
        $employee = $this->employee($request);

        $employee->load(['department:id,name', 'position:id,title', 'manager:id,first_name,middle_name,last_name,suffix', 'workSchedule']);

        return new EmployeeProfileResource($employee);
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
