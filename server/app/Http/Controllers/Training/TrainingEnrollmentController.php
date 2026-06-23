<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Http\Requests\Training\TrainingEnrollmentRequest;
use App\Models\Employee;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Manage who is enrolled in a training program: enroll an employee, update an
 * enrollment (status / score / remarks), or remove it. The completion timestamp
 * tracks the status — stamped when an enrollment is marked completed, cleared
 * otherwise. Thin (route gate `training.manage`).
 */
class TrainingEnrollmentController extends Controller
{
    /**
     * Enroll an employee in the program.
     */
    public function store(TrainingEnrollmentRequest $request, TrainingProgram $trainingProgram): RedirectResponse
    {
        $data = $request->validated();

        if ($trainingProgram->enrollments()->where('employee_id', $data['employee_id'])->exists()) {
            return $this->respond('That employee is already enrolled in this program.', 'warning');
        }

        $trainingProgram->loadCount(['enrollments as active_enrollments_count' => fn ($query) => $query->active()]);

        if ($trainingProgram->isFull()) {
            return $this->respond('This program is full.', 'warning');
        }

        $enrollment = $trainingProgram->enrollments()->create($this->withCompletion($data));
        $employee = Employee::find($data['employee_id']);

        ActivityLogger::log(
            event: 'created',
            description: "Enrolled {$employee?->full_name} in {$trainingProgram->name}",
            subject: $trainingProgram,
            logName: 'training',
            subjectLabel: $trainingProgram->name,
        );

        return $this->respond('Employee enrolled.');
    }

    /**
     * Update an enrollment (status / score / remarks). The employee and program
     * never change here.
     */
    public function update(TrainingEnrollmentRequest $request, TrainingEnrollment $enrollment): RedirectResponse
    {
        $data = $request->safe()->except(['employee_id']);
        $enrollment->update($this->withCompletion($data, $enrollment));

        ActivityLogger::log(
            event: 'updated',
            description: 'Updated a training enrollment',
            subject: $enrollment->program,
            logName: 'training',
            subjectLabel: $enrollment->program?->name,
        );

        return $this->respond('Enrollment updated.');
    }

    /**
     * Remove an enrollment from the program.
     */
    public function destroy(TrainingEnrollment $enrollment): RedirectResponse
    {
        $program = $enrollment->program;
        $enrollment->delete();

        ActivityLogger::log(
            event: 'deleted',
            description: 'Removed a training enrollment',
            subject: $program,
            logName: 'training',
            subjectLabel: $program?->name,
        );

        return $this->respond('Enrollment removed.');
    }

    /**
     * Keep `completed_at` in step with the status: stamp it when an enrollment
     * becomes completed (preserving an existing timestamp), clear it otherwise.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withCompletion(array $data, ?TrainingEnrollment $current = null): array
    {
        if (($data['status'] ?? null) === 'completed') {
            $data['completed_at'] = $current?->completed_at ?? now();
        } else {
            $data['completed_at'] = null;
        }

        return $data;
    }

    private function respond(string $message, string $type = 'success'): RedirectResponse
    {
        Inertia::flash('toast', ['type' => $type, 'message' => $message]);

        return back();
    }
}
