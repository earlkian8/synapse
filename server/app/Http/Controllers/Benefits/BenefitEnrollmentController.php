<?php

namespace App\Http\Controllers\Benefits;

use App\Http\Controllers\Controller;
use App\Http\Requests\Benefits\BenefitEnrollmentRequest;
use App\Models\BenefitEnrollment;
use App\Models\BenefitPlan;
use App\Models\Employee;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Manage who is enrolled in a benefit plan: enroll an employee, update an
 * enrollment (status, reference, dates), or remove it. Thin (route gate
 * `benefits.manage`).
 */
class BenefitEnrollmentController extends Controller
{
    /**
     * Enroll an employee in the plan.
     */
    public function store(BenefitEnrollmentRequest $request, BenefitPlan $benefitPlan): RedirectResponse
    {
        $data = $request->validated();

        if ($benefitPlan->enrollments()->where('employee_id', $data['employee_id'])->exists()) {
            return $this->respond('That employee is already enrolled in this plan.', 'warning');
        }

        $enrollment = $benefitPlan->enrollments()->create($data);
        $employee = Employee::find($data['employee_id']);

        ActivityLogger::log(
            event: 'created',
            description: "Enrolled {$employee?->full_name} in {$benefitPlan->name}",
            subject: $benefitPlan,
            logName: 'benefits',
            subjectLabel: $benefitPlan->name,
        );

        return $this->respond('Employee enrolled.');
    }

    /**
     * Update an enrollment (status / reference / dates / notes). The employee and
     * plan never change here.
     */
    public function update(BenefitEnrollmentRequest $request, BenefitEnrollment $enrollment): RedirectResponse
    {
        $enrollment->update($request->safe()->except(['employee_id']));

        ActivityLogger::log(
            event: 'updated',
            description: 'Updated a benefit enrollment',
            subject: $enrollment->plan,
            logName: 'benefits',
            subjectLabel: $enrollment->plan?->name,
        );

        return $this->respond('Enrollment updated.');
    }

    /**
     * Remove an enrollment from the plan.
     */
    public function destroy(BenefitEnrollment $enrollment): RedirectResponse
    {
        $plan = $enrollment->plan;
        $enrollment->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: 'Removed a benefit enrollment',
            subject: $plan,
            logName: 'benefits',
            subjectLabel: $plan?->name,
        );

        return $this->respond('Enrollment removed.');
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
